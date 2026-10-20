<?php

    namespace FederationLib\Classes\Configuration;

    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Enums\ScanningRules;

    class ScanningConfiguration
    {
        public const float RISK_SCORE_NEUTRAL_POINT = 50.0;
        public const float RISK_SCORE_MIN_BOUND = 0.0;
        public const float RISK_SCORE_MAX_BOUND = 100.0;
        public const int REPUTATION_MIN_BOUND = -1000;
        public const int REPUTATION_MAX_BOUND = 1000;
        private string $autoReportMode;
        private float $modifierAuthorBlacklisted;
        private float $modifierAuthorPermanentlyBlacklisted;
        private float $modifierAuthorWhitelisted;
        private float $modifierAuthorGoodReputation;
        private float $modifierAuthorBadReputation;
        private float $modifierAuthorParentBlacklisted;
        private float $modifierAuthorParentPermanentlyBlacklisted;
        private float $modifierAuthorParentWhitelisted;
        private float $modifierAuthorParentGoodReputation;
        private float $modifierAuthorParentBadReputation;
        private float $modifierNamedEntityBlacklisted;
        private float $modifierNamedEntityPermanentlyBlacklisted;
        private float $modifierNamedEntityWhitelisted;
        private float $modifierNamedEntityGoodReputation;
        private float $modifierNamedEntityBadReputation;
        private float $modifierNamedEntityParentBlacklisted;
        private float $modifierNamedEntityParentPermanentlyBlacklisted;
        private float $modifierNamedEntityParentWhitelisted;
        private float $modifierNamedEntityParentGoodReputation;
        private float $modifierNamedEntityParentBadReputation;
        private float $modifierClassificationNormal;
        private float $modifierClassificationSuspicious;
        private float $modifierClassificationMalicious;
        private float $actionBlockThreshold;
        private float $actionCautionThreshold;
        private int $reputationGain;
        private int $reputationLoss;
        private int $mentionMinAuthors;
        private int $reportReputationNormal;
        private int $reportReputationSuspicious;
        private int $reportReputationMalicious;
        private bool $reportNamedEntityReputation;
        private int $reportNamedEntityReputationNormal;
        private int $reportNamedEntityReputationSuspicious;
        private int $reportNamedEntityReputationMalicious;
        private int $blacklistReputation;
        private int $blacklistRelatedReputation;
        private float $riskScoreScalingFactor;
        /**
         * @var IncidentType[]
         */
        private array $concerns;
        /**
         * @var IncidentType[]
         */
        private array $defaultConcerns;
        private int $dnsLookupLimit;

        /**
         * Constructs a ScanningConfiguration from a configuration array
         *
         * @param array $configuration The scanning configuration values
         */
        public function __construct(array $configuration)
        {
            // Overrides of the points of FederationLib's scanning rules, keyed by rule name (eg; AUTHOR_BLACKLISTED)
            $modifiers = ConfigurationValues::parseMap($configuration['modifiers'] ?? []);
            $this->modifierAuthorBlacklisted = self::getModifier($modifiers, ScanningRules::AUTHOR_BLACKLISTED);
            $this->modifierAuthorPermanentlyBlacklisted = self::getModifier($modifiers, ScanningRules::AUTHOR_PERMANENTLY_BLACKLISTED);
            $this->modifierAuthorWhitelisted = self::getModifier($modifiers, ScanningRules::AUTHOR_WHITELISTED);
            $this->modifierAuthorGoodReputation = self::getModifier($modifiers, ScanningRules::AUTHOR_GOOD_REPUTATION);
            $this->modifierAuthorBadReputation = self::getModifier($modifiers, ScanningRules::AUTHOR_BAD_REPUTATION);
            $this->modifierAuthorParentBlacklisted = self::getModifier($modifiers, ScanningRules::AUTHOR_PARENT_BLACKLISTED);
            $this->modifierAuthorParentPermanentlyBlacklisted = self::getModifier($modifiers, ScanningRules::AUTHOR_PARENT_PERMANENTLY_BLACKLISTED);
            $this->modifierAuthorParentWhitelisted = self::getModifier($modifiers, ScanningRules::AUTHOR_PARENT_WHITELISTED);
            $this->modifierAuthorParentGoodReputation = self::getModifier($modifiers, ScanningRules::AUTHOR_PARENT_GOOD_REPUTATION);
            $this->modifierAuthorParentBadReputation = self::getModifier($modifiers, ScanningRules::AUTHOR_PARENT_BAD_REPUTATION);
            $this->modifierNamedEntityBlacklisted = self::getModifier($modifiers, ScanningRules::NAMED_ENTITY_BLACKLISTED);
            $this->modifierNamedEntityPermanentlyBlacklisted = self::getModifier($modifiers, ScanningRules::NAMED_ENTITY_PERMANENTLY_BLACKLISTED);
            $this->modifierNamedEntityWhitelisted = self::getModifier($modifiers, ScanningRules::NAMED_ENTITY_WHITELISTED);
            $this->modifierNamedEntityGoodReputation = self::getModifier($modifiers, ScanningRules::NAMED_ENTITY_GOOD_REPUTATION);
            $this->modifierNamedEntityBadReputation = self::getModifier($modifiers, ScanningRules::NAMED_ENTITY_BAD_REPUTATION);
            $this->modifierNamedEntityParentBlacklisted = self::getModifier($modifiers, ScanningRules::NAMED_ENTITY_PARENT_BLACKLISTED);
            $this->modifierNamedEntityParentPermanentlyBlacklisted = self::getModifier($modifiers, ScanningRules::NAMED_ENTITY_PARENT_PERMANENTLY_BLACKLISTED);
            $this->modifierNamedEntityParentWhitelisted = self::getModifier($modifiers, ScanningRules::NAMED_ENTITY_PARENT_WHITELISTED);
            $this->modifierNamedEntityParentGoodReputation = self::getModifier($modifiers, ScanningRules::NAMED_ENTITY_PARENT_GOOD_REPUTATION);
            $this->modifierNamedEntityParentBadReputation = self::getModifier($modifiers, ScanningRules::NAMED_ENTITY_PARENT_BAD_REPUTATION);
            $this->modifierClassificationNormal = self::getModifier($modifiers, ScanningRules::CLASSIFICATION_NORMAL);
            $this->modifierClassificationSuspicious = self::getModifier($modifiers, ScanningRules::CLASSIFICATION_SUSPICIOUS);
            $this->modifierClassificationMalicious = self::getModifier($modifiers, ScanningRules::CLASSIFICATION_MALICIOUS);
            $this->actionBlockThreshold = (float)($configuration['action_block_threshold'] ?? 80.00);
            $this->actionCautionThreshold = (float)($configuration['action_caution_threshold'] ?? 60.00);
            $autoReport = $configuration['auto_report'] ?? 'block';
            $autoReport = is_bool($autoReport) ? ($autoReport ? 'block' : 'off') : strtolower(trim((string)$autoReport));
            $this->autoReportMode = in_array($autoReport, ['block', 'caution', 'off'], true) ? $autoReport
                : (ConfigurationValues::parseBool($autoReport, true) ? 'block' : 'off');
            $this->reputationGain = max(0, min(10, (int)($configuration['reputation_gain'] ?? 10)));
            $this->reputationLoss = min(0, (int)($configuration['reputation_loss'] ?? -5));
            $this->mentionMinAuthors = max(1, (int)($configuration['mention_min_authors'] ?? 3));
            $reportReputation = ConfigurationValues::parseMap($configuration['report_reputation'] ?? []);
            $this->reportReputationNormal = max(0, min(10, (int)($reportReputation['NORMAL'] ?? 1)));
            $this->reportReputationSuspicious = min(0, (int)($reportReputation['SUSPICIOUS'] ?? -10));
            $this->reportReputationMalicious = min(0, (int)($reportReputation['MALICIOUS'] ?? -20));
            $namedEntityReputation = $configuration['report_named_entity_reputation'] ?? [];
            $this->reportNamedEntityReputation = !($namedEntityReputation === false
                || (is_string($namedEntityReputation) && in_array(strtolower(trim($namedEntityReputation)), ['false', 'off', 'no', '0'], true)));
            $namedEntityReputation = ConfigurationValues::parseMap($namedEntityReputation);
            $this->reportNamedEntityReputationNormal = max(0, min(10, (int)($namedEntityReputation['NORMAL'] ?? 1)));
            $this->reportNamedEntityReputationSuspicious = min(0, (int)($namedEntityReputation['SUSPICIOUS'] ?? -5));
            $this->reportNamedEntityReputationMalicious = min(0, (int)($namedEntityReputation['MALICIOUS'] ?? -10));
            $this->blacklistReputation = min(0, (int)($configuration['blacklist_reputation'] ?? -50));
            $this->blacklistRelatedReputation = min(0, (int)($configuration['blacklist_related_reputation'] ?? -10));
            $this->riskScoreScalingFactor = (float)($configuration['risk_score_scaling_factor'] ?? 2.3);
            $this->concerns = self::parseConcerns($configuration['concerns'] ?? null, self::getAllConcerns());
            $this->defaultConcerns = array_values(array_filter(
                self::parseConcerns($configuration['default_concerns'] ?? null, self::getStandardDefaultConcerns()),
                fn(IncidentType $concern) => in_array($concern, $this->concerns, true)
            ));
            $this->dnsLookupLimit = max(0, (int)($configuration['dns_lookup_limit'] ?? 10));
        }

        /**
         * Returns every incident type that can be a concern, which is every incident type (OFD "Concern values")
         *
         * @return IncidentType[] The concerns
         */
        public static function getAllConcerns(): array
        {
            return IncidentType::cases();
        }

        /**
         * Returns the concerns assessed by default when a request names none: every concern except ADVERTISING and
         * TRACKING, which OFD recommends hosts do not assess by default so that a service concerned only with
         * unsolicited messages is not affected by them
         *
         * @return IncidentType[] The default concerns
         */
        public static function getStandardDefaultConcerns(): array
        {
            return array_values(array_filter(self::getAllConcerns(),
                fn(IncidentType $type) => $type !== IncidentType::ADVERTISING && $type !== IncidentType::TRACKING
            ));
        }

        /**
         * Parses a configured list of concerns, ignoring values that are not incident types
         *
         * @param mixed $value The configured list, an array or a comma-separated string
         * @param IncidentType[] $default The concerns to use when nothing is configured
         * @return IncidentType[] The concerns
         */
        private static function parseConcerns(mixed $value, array $default): array
        {
            if($value === null)
            {
                return $default;
            }

            $values = is_string($value) ? explode(',', $value) : (is_array($value) ? $value : []);
            $concerns = [];
            foreach($values as $item)
            {
                $concern = is_string($item) ? IncidentType::tryFromCaseInsensitive(trim($item)) : null;
                if($concern !== null)
                {
                    $concerns[$concern->value] = $concern;
                }
            }

            return array_values($concerns);
        }

        /**
         * Returns the author blacklisted score modifier
         *
         * @return float Score modifier
         */
        public function getModifierAuthorBlacklisted(): float
        {
            return $this->modifierAuthorBlacklisted;
        }

        /**
         * Returns the author permanently blacklisted score modifier
         *
         * @return float Score modifier
         */
        public function getModifierAuthorPermanentlyBlacklisted(): float
        {
            return $this->modifierAuthorPermanentlyBlacklisted;
        }

        /**
         * Returns the author whitelisted score modifier
         *
         * @return float Score modifier
         */
        public function getModifierAuthorWhitelisted(): float
        {
            return $this->modifierAuthorWhitelisted;
        }

        /**
         * Returns the author good reputation score modifier
         *
         * @return float Score modifier
         */
        public function getModifierAuthorGoodReputation(): float
        {
            return $this->modifierAuthorGoodReputation;
        }

        /**
         * Returns the author bad reputation score modifier
         *
         * @return float Score modifier
         */
        public function getModifierAuthorBadReputation(): float
        {
            return $this->modifierAuthorBadReputation;
        }

        /**
         * Returns the named entity blacklisted score modifier
         *
         * @return float Score modifier
         */
        public function getModifierNamedEntityBlacklisted(): float
        {
            return $this->modifierNamedEntityBlacklisted;
        }

        /**
         * Returns the named entity permanently blacklisted score modifier
         *
         * @return float Score modifier
         */
        public function getModifierNamedEntityPermanentlyBlacklisted(): float
        {
            return $this->modifierNamedEntityPermanentlyBlacklisted;
        }

        /**
         * Returns the named entity whitelisted score modifier
         *
         * @return float Score modifier
         */
        public function getModifierNamedEntityWhitelisted(): float
        {
            return $this->modifierNamedEntityWhitelisted;
        }

        /**
         * Returns the named entity good reputation score modifier
         *
         * @return float Score modifier
         */
        public function getModifierNamedEntityGoodReputation(): float
        {
            return $this->modifierNamedEntityGoodReputation;
        }

        /**
         * Returns the named entity bad reputation score modifier
         *
         * @return float Score modifier
         */
        public function getModifierNamedEntityBadReputation(): float
        {
            return $this->modifierNamedEntityBadReputation;
        }

        /**
         * Returns the author parent blacklisted score modifier
         *
         * @return float Score modifier
         */
        public function getModifierAuthorParentBlacklisted(): float
        {
            return $this->modifierAuthorParentBlacklisted;
        }

        /**
         * Returns the author parent permanently blacklisted score modifier
         *
         * @return float Score modifier
         */
        public function getModifierAuthorParentPermanentlyBlacklisted(): float
        {
            return $this->modifierAuthorParentPermanentlyBlacklisted;
        }

        /**
         * Returns the author parent whitelisted score modifier
         *
         * @return float Score modifier
         */
        public function getModifierAuthorParentWhitelisted(): float
        {
            return $this->modifierAuthorParentWhitelisted;
        }

        /**
         * Returns the author parent good reputation score modifier
         *
         * @return float Score modifier
         */
        public function getModifierAuthorParentGoodReputation(): float
        {
            return $this->modifierAuthorParentGoodReputation;
        }

        /**
         * Returns the author parent bad reputation score modifier
         *
         * @return float Score modifier
         */
        public function getModifierAuthorParentBadReputation(): float
        {
            return $this->modifierAuthorParentBadReputation;
        }

        /**
         * Returns the named entity parent blacklisted score modifier
         *
         * @return float Score modifier
         */
        public function getModifierNamedEntityParentBlacklisted(): float
        {
            return $this->modifierNamedEntityParentBlacklisted;
        }

        /**
         * Returns the named entity parent permanently blacklisted score modifier
         *
         * @return float Score modifier
         */
        public function getModifierNamedEntityParentPermanentlyBlacklisted(): float
        {
            return $this->modifierNamedEntityParentPermanentlyBlacklisted;
        }

        /**
         * Returns the named entity parent whitelisted score modifier
         *
         * @return float Score modifier
         */
        public function getModifierNamedEntityParentWhitelisted(): float
        {
            return $this->modifierNamedEntityParentWhitelisted;
        }

        /**
         * Returns the named entity parent good reputation score modifier
         *
         * @return float Score modifier
         */
        public function getModifierNamedEntityParentGoodReputation(): float
        {
            return $this->modifierNamedEntityParentGoodReputation;
        }

        /**
         * Returns the named entity parent bad reputation score modifier
         *
         * @return float Score modifier
         */
        public function getModifierNamedEntityParentBadReputation(): float
        {
            return $this->modifierNamedEntityParentBadReputation;
        }

        /**
         * Returns the classification normal score modifier
         *
         * @return float Score modifier
         */
        public function getModifierClassificationNormal(): float
        {
            return $this->modifierClassificationNormal;
        }

        /**
         * Returns the classification suspicious score modifier
         *
         * @return float Score modifier
         */
        public function getModifierClassificationSuspicious(): float
        {
            return $this->modifierClassificationSuspicious;
        }

        /**
         * Returns the classification malicious score modifier
         *
         * @return float Score modifier
         */
        public function getModifierClassificationMalicious(): float
        {
            return $this->modifierClassificationMalicious;
        }

        /**
         * Returns whether auto-reporting is enabled
         *
         * @return bool True if auto-report is enabled
         */
        public function isAutoReport(): bool
        {
            return $this->autoReportMode !== 'off';
        }

        /**
         * Returns the auto-report threshold
         *
         * @return float Auto-report threshold
         */
        public function getAutoReportThreshold(): float
        {
            return $this->autoReportMode === 'caution' ? $this->actionCautionThreshold : $this->actionBlockThreshold;
        }

        /**
         * Returns whether reports should also be generated for scans that suggest the CAUTION action
         *
         * @return bool True if caution auto-reporting is enabled
         */
        public function isAutoReportCaution(): bool
        {
            return $this->autoReportMode === 'caution';
        }

        /**
         * Returns the risk score threshold at which content should be blocked
         *
         * @return float Action block threshold
         */
        public function getActionBlockThreshold(): float
        {
            return $this->actionBlockThreshold;
        }

        /**
         * Returns the risk score threshold at which caution should be advised
         *
         * @return float Action caution threshold
         */
        public function getActionCautionThreshold(): float
        {
            return $this->actionCautionThreshold;
        }

        /**
         * Returns the reputation an entity gains for a day of normal activity, before it tapers above 500
         *
         * @return int Reputation gain per day, 0 to 10
         */
        public function getReputationGain(): int
        {
            return $this->reputationGain;
        }

        /**
         * Returns the reputation an author loses for a day on which content it authored reached the block threshold
         *
         * @return int Reputation loss per day, 0 or below
         */
        public function getReputationLoss(): int
        {
            return $this->reputationLoss;
        }

        /**
         * Returns the number of distinct authors that must mention an entity in normal content on a day for the entity
         * to gain reputation from the mentions
         *
         * @return int The minimum number of distinct authors
         */
        public function getMentionMinAuthors(): int
        {
            return $this->mentionMinAuthors;
        }

        /**
         * Returns the minimum reputation bound
         *
         * @return int Minimum bound
         */
        public function getReputationMinBound(): int
        {
            return self::REPUTATION_MIN_BOUND;
        }

        /**
         * Returns the maximum reputation bound
         *
         * @return int Maximum bound
         */
        public function getReputationMaxBound(): int
        {
            return self::REPUTATION_MAX_BOUND;
        }

        /**
         * Returns the reputation adjustment applied to the reported entity when a report is closed with the
         * given classification flag
         *
         * @param ClassificationFlag $classificationFlag The classification flag the report was closed with
         * @return int The reputation delta, 0 for no adjustment
         */
        public function getReportReputation(ClassificationFlag $classificationFlag): int
        {
            return match($classificationFlag)
            {
                ClassificationFlag::NORMAL => $this->reportReputationNormal,
                ClassificationFlag::SUSPICIOUS => $this->reportReputationSuspicious,
                ClassificationFlag::MALICIOUS => $this->reportReputationMalicious,
            };
        }

        /**
         * Returns whether closing a classified report also adjusts the reputation of the entities mentioned
         * within the report's evidence
         *
         * @return bool True if named entities are affected, false otherwise
         */
        public function isReportNamedEntityReputationEnabled(): bool
        {
            return $this->reportNamedEntityReputation;
        }

        /**
         * Returns the reputation adjustment applied to each entity mentioned within a report's evidence when the
         * report is closed with the given classification flag
         *
         * @param ClassificationFlag $classificationFlag The classification flag the report was closed with
         * @return int The reputation delta, 0 for no adjustment
         */
        public function getReportNamedEntityReputation(ClassificationFlag $classificationFlag): int
        {
            return match($classificationFlag)
            {
                ClassificationFlag::NORMAL => $this->reportNamedEntityReputationNormal,
                ClassificationFlag::SUSPICIOUS => $this->reportNamedEntityReputationSuspicious,
                ClassificationFlag::MALICIOUS => $this->reportNamedEntityReputationMalicious,
            };
        }

        /**
         * Returns the reputation adjustment applied to an entity when it is blacklisted
         *
         * @return int The reputation delta, 0 or below
         */
        public function getBlacklistReputation(): int
        {
            return $this->blacklistReputation;
        }

        /**
         * Returns the reputation adjustment applied to the entities directly related to a blacklisted entity
         *
         * @return int The reputation delta, 0 or below
         */
        public function getBlacklistRelatedReputation(): int
        {
            return $this->blacklistRelatedReputation;
        }

        /**
         * Returns the risk score neutral point
         *
         * @return float Neutral point value
         */
        public function getRiskScoreNeutralPoint(): float
        {
            return self::RISK_SCORE_NEUTRAL_POINT;
        }

        /**
         * Returns the risk score scaling factor
         *
         * @return float Scaling factor
         */
        public function getRiskScoreScalingFactor(): float
        {
            return $this->riskScoreScalingFactor;
        }

        /**
         * Returns the minimum risk score bound
         *
         * @return float Minimum bound
         */
        public function getRiskScoreMinBound(): float
        {
            return self::RISK_SCORE_MIN_BOUND;
        }

        /**
         * Returns the maximum risk score bound
         *
         * @return float Maximum bound
         */
        public function getRiskScoreMaxBound(): float
        {
            return self::RISK_SCORE_MAX_BOUND;
        }
    
        /**
         * Returns the concerns the host can assess, published as the concerns member of the ServerInformation object
         *
         * @return IncidentType[] The concerns
         */
        public function getConcerns(): array
        {
            return $this->concerns;
        }

        /**
         * Returns the concerns the host assesses when a request names none, a subset of getConcerns()
         *
         * @return IncidentType[] The default concerns
         */
        public function getDefaultConcerns(): array
        {
            return $this->defaultConcerns;
        }

        /**
         * Returns whether the host resolves the DNS names submitted for scanning (HOSTNAME content, and the hosts of URL
         * and WEB_PAGE content) itself, rather than relying on what the client asserts about them
         *
         * @return bool True if DNS lookups are performed
         */
        public function isDnsLookupsEnabled(): bool
        {
            return $this->dnsLookupLimit > 0;
        }

        /**
         * Returns the maximum number of DNS names a single scan request may cause the host to resolve
         *
         * @return int The maximum number of DNS lookups per request
         */
        public function getDnsLookupLimit(): int
        {
            return $this->dnsLookupLimit;
        }


        /**
         * Returns whether the host may store content submitted for scanning by unauthenticated requests, or records
         * derived from it, published as collects_public_scan_content (OFD "Content Scanning"). This is the case when
         * content scanning is public and either automatic reports, whose evidence records the scanned content, are
         * generated, or a plugin declares that it stores scanned content.
         *
         * @param bool $scanContentPublic Whether content scanning is publicly accessible
         * @param bool $pluginsCollect Whether a loaded plugin declares that it stores scanned content
         * @return bool True if content submitted by unauthenticated requests may be stored
         */
        public function collectsPublicScanContent(bool $scanContentPublic, bool $pluginsCollect=false): bool
        {
            return $scanContentPublic && ($this->isAutoReport() || $pluginsCollect);
        }

        /**
         * Returns the points of a scanning rule: the configured override, or the rule's default points
         *
         * @param array<string, mixed> $modifiers The configured overrides keyed by rule name
         * @param ScanningRules $rule The scanning rule
         * @return float The points
         */
        private static function getModifier(array $modifiers, ScanningRules $rule): float
        {
            return is_numeric($modifiers[$rule->name] ?? null) ? (float)$modifiers[$rule->name] : $rule->getModifier();
        }
    }
