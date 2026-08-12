<?php

    namespace FederationLib\Objects;

    use FederationLib\Classes\Configuration;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\ScanningRules;
    use FederationLib\Enums\SuggestedActionType;
    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use FederationLib\Interfaces\StandardObjectInterface;
    use FederationLib\Objects\ScannedContent\ContentClassification;
    use FederationLib\Objects\ScannedContent\ResolvedEntity;

    class ScannedContent implements StandardObjectInterface, ObjectSpecificationInterface
    {
        /**
         * @var ResolvedEntity[]
         */
        private array $resolvedEntities;
        private ?ResolvedEntity $authorEntity;

        /**
         * @var ContentClassification[]
         */
        private array $classifications;

        /**
         * ScannedContent Public Constructor
         *
         * @param ResolvedEntity[] $resolvedEntities An array of resolved entities from the text content
         * @param ResolvedEntity|null $authorEntity Optional. The author entity of the submitted content
         * @param ContentClassification|ContentClassification[]|null $classification Optional. The classification information about the submitted content
         */
        public function __construct(array $resolvedEntities, ?ResolvedEntity $authorEntity=null, ContentClassification|array|null $classification=null)
        {
            $this->resolvedEntities = $resolvedEntities;
            $this->authorEntity = $authorEntity;

            if($classification === null)
            {
                $this->classifications = [];
            }
            elseif($classification instanceof ContentClassification)
            {
                $this->classifications = [$classification];
            }
            else
            {
                $this->classifications = array_values(array_filter($classification, fn($c) => $c instanceof ContentClassification));
            }
        }

        /**
         * Returns the array of resolved entities from the text content
         *
         * @return ResolvedEntity[] An array of resolved entities
         */
        public function getResolvedEntities(): array
        {
            return $this->resolvedEntities;
        }

        /**
         * Returns the author entity result
         *
         * @return ResolvedEntity|null
         */
        public function getAuthorEntity(): ?ResolvedEntity
        {
            return $this->authorEntity;
        }

        /**
         * Returns all content classification results
         *
         * @return ContentClassification[]
         */
        public function getClassifications(): array
        {
            return $this->classifications;
        }

        /**
         * Returns the aggregate content classification result.
         * If multiple classifications are present, the worst flag is returned with the average confidence.
         *
         * @return ContentClassification|null The content classification result
         */
        public function getClassification(): ?ContentClassification
        {
            if(empty($this->classifications))
            {
                return null;
            }

            if(count($this->classifications) === 1)
            {
                return $this->classifications[0];
            }

            $hasMalicious = false;
            $hasSuspicious = false;
            $totalConfidence = 0.0;
            $count = 0;
            $languageCode = null;

            foreach($this->classifications as $classification)
            {
                switch($classification->getClassificationFlag())
                {
                    case ClassificationFlag::MALICIOUS:
                        $hasMalicious = true;
                        break;

                    case ClassificationFlag::SUSPICIOUS:
                        $hasSuspicious = true;
                        break;
                }

                $totalConfidence += $classification->getConfidence();
                $count++;

                if($languageCode === null)
                {
                    $languageCode = $classification->getDetectedLanguage();
                }
            }

            $flag = $hasMalicious ? ClassificationFlag::MALICIOUS : ($hasSuspicious ? ClassificationFlag::SUSPICIOUS : ClassificationFlag::NORMAL);
            $confidence = $count > 0 ? $totalConfidence / $count : 0.0;

            return new ContentClassification($flag, $confidence, $languageCode);
        }

        /**
         * Returns the suggested action to take against the scanned content
         *
         * @return SuggestedActionType|null
         */
        public function getSuggestedAction(): ?SuggestedActionType
        {
            // If the author contains one or more active blacklists, the author should be blocked
            if($this->authorEntity !== null && count($this->authorEntity->getActiveBlacklists()) > 0)
            {
                // If any of the blacklist records is permanent, the entity should be blocked permanently
                if (array_any($this->authorEntity->getActiveBlacklists(), fn($blacklistRecord) => $blacklistRecord->getExpires() === null))
                {
                    return SuggestedActionType::PERMANENTLY_BLOCK_ENTITY;
                }

                // Otherwise the entity should be blocked temporarily
                return SuggestedActionType::TEMPORARILY_BLOCK_ENTITY;
            }

            $riskScore = $this->getRiskScore();
            $scanningConfiguration = Configuration::getScanningConfiguration();

            // If the risk score is high (80+), it's malicious; block content
            if($riskScore >= $scanningConfiguration->getActionBlockThreshold())
            {
                return SuggestedActionType::BLOCK_CONTENT;
            }
            // If the risk score indicates caution is needed (60-90)
            elseif($riskScore >= $scanningConfiguration->getActionCautionThreshold())
            {
                return SuggestedActionType::CAUTION;
            }

            // Otherwise return null for no suggested action
            return null;
        }

        /**
         * Returns the suggested time limit for the suggested action to take effect for, returns null if the
         * time limit is not applicable, otherwise returns the Unix Timestamp for when the effect is lifted at
         *
         * @return int|null The Unix Timestamp of the effect limit, null otherwise.
         */
        public function getSuggestedLiftTimestamp(): ?int
        {
            // Return null if there is no author entity
            if($this->authorEntity === null)
            {
                return null;
            }

            // Return null if the suggested action is not to block the entity
            if($this->getSuggestedAction() !== SuggestedActionType::TEMPORARILY_BLOCK_ENTITY)
            {
                return null;
            }

            $longestActiveBlacklist = 0;
            /** @var BlacklistRecord $activeBlacklistRecord */
            foreach($this->authorEntity->getActiveBlacklists() as $activeBlacklistRecord)
            {
                if($activeBlacklistRecord->getExpires() === null)
                {
                    // The entity is permanently blacklisted
                    return PHP_INT_MAX;
                }

                // Update the current expiration we have
                if($activeBlacklistRecord->getExpires() > $longestActiveBlacklist)
                {
                    $longestActiveBlacklist = $activeBlacklistRecord->getExpires();
                }
            }

            if($longestActiveBlacklist > 0)
            {
                return $longestActiveBlacklist;
            }

            // Return null in all other cases
            return null;
        }

        /**
         * Returns the result of all the scanning rules applied to the scanned content, returning an array of points
         * assigned per scanning rule
         *
         * @return array An array result of all the scan pattern points
         */
        public function getScanResults(): array
        {
            $scanningRules = ScanningRules::newTable();
            $config = Configuration::getScanningConfiguration();

            if($this->authorEntity !== null)
            {
                self::applyWhitelistBlacklistRules($scanningRules, $this->authorEntity,
                    ScanningRules::AUTHOR_WHITELISTED, ScanningRules::AUTHOR_PERMANENTLY_BLACKLISTED, ScanningRules::AUTHOR_BLACKLISTED,
                    $config->getAuthorWhitelisted(), $config->getAuthorPermanentlyBlacklisted(), $config->getAuthorBlacklisted()
                );

                self::applyReputationRules($scanningRules, $this->authorEntity,
                    ScanningRules::AUTHOR_GOOD_REPUTATION, ScanningRules::AUTHOR_BAD_REPUTATION,
                    $config->getAuthorGoodReputation(), $config->getAuthorBadReputation()
                );

                $authorParent = $this->authorEntity->getParentEntity();
                if($authorParent !== null)
                {
                    self::applyWhitelistBlacklistRules($scanningRules, $authorParent,
                        ScanningRules::AUTHOR_PARENT_WHITELISTED, ScanningRules::AUTHOR_PARENT_PERMANENTLY_BLACKLISTED, ScanningRules::AUTHOR_PARENT_BLACKLISTED,
                        $config->getAuthorParentWhitelisted(), $config->getAuthorParentPermanentlyBlacklisted(), $config->getAuthorParentBlacklisted()
                    );

                    self::applyReputationRules($scanningRules, $authorParent,
                        ScanningRules::AUTHOR_PARENT_GOOD_REPUTATION, ScanningRules::AUTHOR_PARENT_BAD_REPUTATION,
                        $config->getAuthorParentGoodReputation(), $config->getAuthorParentBadReputation()
                    );
                }
            }

            foreach($this->resolvedEntities as $resolvedEntity)
            {
                self::applyWhitelistBlacklistRules($scanningRules, $resolvedEntity,
                    ScanningRules::NAMED_ENTITY_WHITELISTED, ScanningRules::NAMED_ENTITY_PERMANENTLY_BLACKLISTED, ScanningRules::NAMED_ENTITY_BLACKLISTED,
                    $config->getNamedEntityWhitelisted(), $config->getNamedEntityPermanentlyBlacklisted(), $config->getNamedEntityBlacklisted()
                );

                self::applyReputationRules($scanningRules, $resolvedEntity,
                    ScanningRules::NAMED_ENTITY_GOOD_REPUTATION, ScanningRules::NAMED_ENTITY_BAD_REPUTATION,
                    $config->getNamedEntityGoodReputation(), $config->getNamedEntityBadReputation()
                );

                $entityParent = $resolvedEntity->getParentEntity();
                if($entityParent !== null)
                {
                    self::applyWhitelistBlacklistRules($scanningRules, $entityParent,
                        ScanningRules::NAMED_ENTITY_PARENT_WHITELISTED, ScanningRules::NAMED_ENTITY_PARENT_PERMANENTLY_BLACKLISTED, ScanningRules::NAMED_ENTITY_PARENT_BLACKLISTED,
                        $config->getNamedEntityParentWhitelisted(), $config->getNamedEntityParentPermanentlyBlacklisted(), $config->getNamedEntityParentBlacklisted()
                    );

                    self::applyReputationRules($scanningRules, $entityParent,
                        ScanningRules::NAMED_ENTITY_PARENT_GOOD_REPUTATION, ScanningRules::NAMED_ENTITY_PARENT_BAD_REPUTATION,
                        $config->getNamedEntityParentGoodReputation(), $config->getNamedEntityParentBadReputation()
                    );
                }
            }

            foreach($this->classifications as $classification)
            {
                self::applyClassificationRules($scanningRules, $classification);
            }

            return $scanningRules;
        }

        /**
         * Returns the computed risk score.
         * A score at the neutral point means no risk deviation.
         * Scales between the configured min and max bounds.
         *
         * @return float The calculated risk score formatted to 2 decimal places.
         */
        public function getRiskScore(): float
        {
            $scanResults = $this->getScanResults();
            $accumulatedPoints = 0.0;

            foreach (ScanningRules::cases() as $rule)
            {
                $accumulatedPoints += ($scanResults[$rule->name] ?? 0.0);
            }

            $neutralPoint = Configuration::getScanningConfiguration()->getRiskScoreNeutralPoint();
            $scalingFactor = Configuration::getScanningConfiguration()->getRiskScoreScalingFactor();
            $minBound = Configuration::getScanningConfiguration()->getRiskScoreMinBound();
            $maxBound = Configuration::getScanningConfiguration()->getRiskScoreMaxBound();

            return round(max($minBound, min($maxBound, ($neutralPoint - ($accumulatedPoints * $scalingFactor)))), 2);
        }

        /**
         * Applies the whitelist/blacklist rules to the scanning rules
         *
         * @param array $scanningRules The scanning rules
         * @param ResolvedEntity $entity The resolved entity
         * @param ScanningRules $whitelistedRule The whitelisted scanning rules
         * @param ScanningRules $permBlacklistedRule The blacklisted scanning rules
         * @param ScanningRules $blacklistedRule The blacklisted scanning rules
         * @param float $whitelistedPoints The Whitelisted points
         * @param float $permBlacklistedPoints The Permanently Blacklisted points
         * @param float $blacklistedPoints The Blacklisted points
         */
        private static function applyWhitelistBlacklistRules(array &$scanningRules, ResolvedEntity $entity,
            ScanningRules $whitelistedRule, ScanningRules $permBlacklistedRule, ScanningRules $blacklistedRule,
            float $whitelistedPoints, float $permBlacklistedPoints, float $blacklistedPoints,
        ): void
        {
            if($entity->getEntity()->isWhitelisted())
            {
                $scanningRules[$whitelistedRule->name] += $whitelistedPoints;
            }
            elseif(count($entity->getActiveBlacklists()) > 0)
            {
                foreach($entity->getActiveBlacklists() as $blacklistRecord)
                {
                    if($blacklistRecord->getExpires() === null)
                    {
                        $scanningRules[$permBlacklistedRule->name] += $permBlacklistedPoints;
                    }
                    else
                    {
                        $scanningRules[$blacklistedRule->name] += $blacklistedPoints;
                    }
                }
            }
        }

        /**
         * Applies the reputation rules to the scanning rules
         *
         * @param array $scanningRules The scanning rules
         * @param ResolvedEntity $entity The resolved entity
         * @param ScanningRules $goodReputationRule The good reputation rules
         * @param ScanningRules $badReputationRule The bad reputation rules
         * @param float $goodReputationPoints The good reputation points
         * @param float $badReputationPoints The bad reputation points
         */
        private static function applyReputationRules(array &$scanningRules, ResolvedEntity $entity, ScanningRules $goodReputationRule,
            ScanningRules $badReputationRule, float $goodReputationPoints, float $badReputationPoints,
        ): void
        {
            if($entity->getEntity()->isWhitelisted())
            {
                return;
            }

            $reputation = $entity->getEntity()->getReputation();
            if($reputation === 0)
            {
                return;
            }

            $config = Configuration::getScanningConfiguration();

            if($reputation > 0)
            {
                $maxBound = $config->getReputationMaxBound();
                if($maxBound <= 0)
                {
                    return;
                }

                $factor = pow(min($reputation, $maxBound) / $maxBound, 1.0 / 3.0);
                $scanningRules[$goodReputationRule->name] += $goodReputationPoints * $factor;
            }
            else
            {
                $minBound = $config->getReputationMinBound();
                if($minBound >= 0)
                {
                    return;
                }

                $factor = pow(min(abs($reputation), abs($minBound)) / abs($minBound), 1.0 / 3.0);
                $scanningRules[$badReputationRule->name] += $badReputationPoints * $factor;
            }
        }

        /**
         * Applies the classification rules against the scanning rules table
         *
         * @param array $scanningRules The scanning rules
         * @param ContentClassification|null $classification Content classification, null if not available
         */
        private static function applyClassificationRules(array &$scanningRules, ?ContentClassification $classification): void
        {
            if($classification === null)
            {
                return;
            }

            $config = Configuration::getScanningConfiguration();

            $points = match($classification->getClassificationFlag())
            {
                ClassificationFlag::NORMAL => $config->getClassificationNormal(),
                ClassificationFlag::SUSPICIOUS => $config->getClassificationSuspicious(),
                ClassificationFlag::MALICIOUS => $config->getClassificationMalicious(),
            };

            $rule = match($classification->getClassificationFlag())
            {
                ClassificationFlag::NORMAL => ScanningRules::CLASSIFICATION_NORMAL,
                ClassificationFlag::SUSPICIOUS => ScanningRules::CLASSIFICATION_SUSPICIOUS,
                ClassificationFlag::MALICIOUS => ScanningRules::CLASSIFICATION_MALICIOUS,
            };

            $scanningRules[$rule->name] += $points * $classification->getConfidence();
        }

        /**
         * @inheritDoc
         */
        public function toArray(bool $includeMetadata=false): array
        {
            return [
                'author_entity' => $this->authorEntity?->toArray($includeMetadata) ?? null,
                'resolved_entities' => array_map(fn($resolvedEntity) => $resolvedEntity->toArray($includeMetadata), $this->resolvedEntities),
                'classification' => $this->getClassification()?->toArray() ?? null
            ];
        }

        /**
         * @inheritDoc
         */
        public function toStandardArray(bool $includeMetadata=false): array
        {
            return [
                'author_entity' => $this->authorEntity?->toArray($includeMetadata) ?? null,
                'resolved_entities' => array_map(fn($resolvedEntity) => $resolvedEntity->toArray($includeMetadata), $this->resolvedEntities),
                'suggested_action' => $this->getSuggestedAction()?->value ?? null,
                'suggested_lift_timestamp' => $this->getSuggestedLiftTimestamp(),
                'classification' => $this->getClassification()?->toArray() ?? null,
                'scan_results' => (object) $this->getScanResults(),
                'risk_score' => $this->getRiskScore()
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): ScannedContent
        {
            $classification = null;
            if(isset($array['classification']) && !is_null($array['classification']))
            {
               $classification = ContentClassification::fromArray($array['classification']);
            }

            $authorEntity = null;
            if(isset($array['author_entity']) && $array['author_entity'] !== null)
            {
                $authorEntity = ResolvedEntity::fromArray($array['author_entity']);
            }

            return new self(
                array_map(fn($resolvedEntity) => ResolvedEntity::fromArray($resolvedEntity), $array['resolved_entities']),
                $authorEntity, $classification
            );
        }

        /**
         * @inheritDoc
         */
        public static function getObjectType(): string
        {
            return 'object';
        }

        /**
         * @inheritDoc
         */
        public static function getObjectProperties(): array
        {
            return [
                'resolved_entities' => [
                    'type' => 'array',
                    'items' => ['$ref' => ResolvedEntity::getReference()],
                    'description' => 'Resolved entities found in the scanned content',
                ],
                'author_entity' => ['$ref' => ResolvedEntity::getReference(), 'description' => 'The author entity', 'nullable' => true],
                'classification' => ['$ref' => ContentClassification::getReference(), 'description' => 'Content classification result', 'nullable' => true],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getObjectRequired(): array
        {
            return ['resolved_entities', 'classification'];
        }

        /**
         * @inheritDoc
         */
        public static function getReference(): string
        {
            return '#/components/schemas/ScannedContent';
        }
    }
