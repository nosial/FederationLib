<?php

    namespace FederationLib\Classes\Configuration;

    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\ScanningRules;

    class ScanningConfiguration
    {
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
        private bool $autoReport;
        private float $autoReportThreshold;
        private bool $autoReportCaution;
        private float $actionBlockThreshold;
        private float $actionCautionThreshold;
        private int $reputationWindowDuration;
        private int $reputationGain;
        private int $reputationMinBound;
        private int $reputationMaxBound;
        private int $reportReputationNormal;
        private int $reportReputationSuspicious;
        private int $reportReputationMalicious;
        private bool $reportNamedEntityReputation;
        private int $reportNamedEntityReputationNormal;
        private int $reportNamedEntityReputationSuspicious;
        private int $reportNamedEntityReputationMalicious;
        private int $blacklistReputation;
        private int $blacklistRelatedReputation;
        private float $riskScoreNeutralPoint;
        private float $riskScoreScalingFactor;
        private float $riskScoreMinBound;
        private float $riskScoreMaxBound;

        /**
         * Constructs a ScanningConfiguration from a configuration array
         *
         * @param array $configuration The scanning configuration values
         */
        public function __construct(array $configuration)
        {
            $this->modifierAuthorBlacklisted = (float)($configuration['modifier_author_blacklisted'] ?? ScanningRules::AUTHOR_BLACKLISTED->getModifier());
            $this->modifierAuthorPermanentlyBlacklisted = (float)($configuration['modifier_author_permanently_blacklisted'] ?? ScanningRules::AUTHOR_PERMANENTLY_BLACKLISTED->getModifier());
            $this->modifierAuthorWhitelisted = (float)($configuration['modifier_author_whitelisted'] ?? ScanningRules::AUTHOR_WHITELISTED->getModifier());
            $this->modifierAuthorGoodReputation = (float)($configuration['modifier_author_good_reputation'] ?? ScanningRules::AUTHOR_GOOD_REPUTATION->getModifier());
            $this->modifierAuthorBadReputation = (float)($configuration['modifier_author_bad_reputation'] ?? ScanningRules::AUTHOR_BAD_REPUTATION->getModifier());
            $this->modifierAuthorParentBlacklisted = (float)($configuration['modifier_author_parent_blacklisted'] ?? ScanningRules::AUTHOR_PARENT_BLACKLISTED->getModifier());
            $this->modifierAuthorParentPermanentlyBlacklisted = (float)($configuration['modifier_author_parent_permanently_blacklisted'] ?? ScanningRules::AUTHOR_PARENT_PERMANENTLY_BLACKLISTED->getModifier());
            $this->modifierAuthorParentWhitelisted = (float)($configuration['modifier_author_parent_whitelisted'] ?? ScanningRules::AUTHOR_PARENT_WHITELISTED->getModifier());
            $this->modifierAuthorParentGoodReputation = (float)($configuration['modifier_author_parent_good_reputation'] ?? ScanningRules::AUTHOR_PARENT_GOOD_REPUTATION->getModifier());
            $this->modifierAuthorParentBadReputation = (float)($configuration['modifier_author_parent_bad_reputation'] ?? ScanningRules::AUTHOR_PARENT_BAD_REPUTATION->getModifier());
            $this->modifierNamedEntityBlacklisted = (float)($configuration['modifier_named_entity_blacklisted'] ?? ScanningRules::NAMED_ENTITY_BLACKLISTED->getModifier());
            $this->modifierNamedEntityPermanentlyBlacklisted = (float)($configuration['modifier_named_entity_permanently_blacklisted'] ?? ScanningRules::NAMED_ENTITY_PERMANENTLY_BLACKLISTED->getModifier());
            $this->modifierNamedEntityWhitelisted = (float)($configuration['modifier_named_entity_whitelisted'] ?? ScanningRules::NAMED_ENTITY_WHITELISTED->getModifier());
            $this->modifierNamedEntityGoodReputation = (float)($configuration['modifier_named_entity_good_reputation'] ?? ScanningRules::NAMED_ENTITY_GOOD_REPUTATION->getModifier());
            $this->modifierNamedEntityBadReputation = (float)($configuration['modifier_named_entity_bad_reputation'] ?? ScanningRules::NAMED_ENTITY_BAD_REPUTATION->getModifier());
            $this->modifierNamedEntityParentBlacklisted = (float)($configuration['modifier_named_entity_parent_blacklisted'] ?? ScanningRules::NAMED_ENTITY_PARENT_BLACKLISTED->getModifier());
            $this->modifierNamedEntityParentPermanentlyBlacklisted = (float)($configuration['modifier_named_entity_parent_permanently_blacklisted'] ?? ScanningRules::NAMED_ENTITY_PARENT_PERMANENTLY_BLACKLISTED->getModifier());
            $this->modifierNamedEntityParentWhitelisted = (float)($configuration['modifier_named_entity_parent_whitelisted'] ?? ScanningRules::NAMED_ENTITY_PARENT_WHITELISTED->getModifier());
            $this->modifierNamedEntityParentGoodReputation = (float)($configuration['modifier_named_entity_parent_good_reputation'] ?? ScanningRules::NAMED_ENTITY_PARENT_GOOD_REPUTATION->getModifier());
            $this->modifierNamedEntityParentBadReputation = (float)($configuration['modifier_named_entity_parent_bad_reputation'] ?? ScanningRules::NAMED_ENTITY_PARENT_BAD_REPUTATION->getModifier());
            $this->modifierClassificationNormal = (float)($configuration['modifier_classification_normal'] ?? ScanningRules::CLASSIFICATION_NORMAL->getModifier());
            $this->modifierClassificationSuspicious = (float)($configuration['modifier_classification_suspicious'] ?? ScanningRules::CLASSIFICATION_SUSPICIOUS->getModifier());
            $this->modifierClassificationMalicious = (float)($configuration['modifier_classification_malicious'] ?? ScanningRules::CLASSIFICATION_MALICIOUS->getModifier());
            $this->autoReport = (bool)($configuration['auto_report'] ?? false);
            $this->autoReportThreshold = (float)($configuration['auto_report_threshold'] ?? 80.00);
            $this->autoReportCaution = (bool)($configuration['auto_report_caution'] ?? false);
            $this->actionBlockThreshold = (float)($configuration['action_block_threshold'] ?? 80.00);
            $this->actionCautionThreshold = (float)($configuration['action_caution_threshold'] ?? 60.00);
            $this->reputationWindowDuration = (int)($configuration['reputation_window_duration'] ?? 3600);
            // OFD: incremental positive adjustments SHOULD NOT exceed 10 points; 0 disables gains
            $this->reputationGain = max(0, min(10, (int)($configuration['reputation_gain'] ?? 1)));
            $this->reputationMinBound = (int)($configuration['reputation_min_bound'] ?? -1000);
            $this->reputationMaxBound = (int)($configuration['reputation_max_bound'] ?? 1000);
            // Reputation adjustments applied when a report is closed with a classification flag. Positive
            // adjustments are clamped to 0-10 per the OFD incremental gain rule, negative ones to 0 or below.
            $this->reportReputationNormal = max(0, min(10, (int)($configuration['report_reputation_normal'] ?? 1)));
            $this->reportReputationSuspicious = min(0, (int)($configuration['report_reputation_suspicious'] ?? -10));
            $this->reportReputationMalicious = min(0, (int)($configuration['report_reputation_malicious'] ?? -20));
            $this->reportNamedEntityReputation = (bool)($configuration['report_named_entity_reputation'] ?? true);
            $this->reportNamedEntityReputationNormal = max(0, min(10, (int)($configuration['report_named_entity_reputation_normal'] ?? 1)));
            $this->reportNamedEntityReputationSuspicious = min(0, (int)($configuration['report_named_entity_reputation_suspicious'] ?? -5));
            $this->reportNamedEntityReputationMalicious = min(0, (int)($configuration['report_named_entity_reputation_malicious'] ?? -10));
            // Reputation decreases applied when an entity is blacklisted, clamped to 0 or below
            $this->blacklistReputation = min(0, (int)($configuration['blacklist_reputation'] ?? -50));
            $this->blacklistRelatedReputation = min(0, (int)($configuration['blacklist_related_reputation'] ?? -10));
            $this->riskScoreNeutralPoint = (float)($configuration['risk_score_neutral_point'] ?? 50.0);
            $this->riskScoreScalingFactor = (float)($configuration['risk_score_scaling_factor'] ?? 2.3);
            $this->riskScoreMinBound = (float)($configuration['risk_score_min_bound'] ?? 0.0);
            $this->riskScoreMaxBound = (float)($configuration['risk_score_max_bound'] ?? 100.0);
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
            return $this->autoReport;
        }

        /**
         * Returns the auto-report threshold
         *
         * @return float Auto-report threshold
         */
        public function getAutoReportThreshold(): float
        {
            return $this->autoReportThreshold;
        }

        /**
         * Returns whether reports should also be generated for scans that suggest the CAUTION action
         *
         * @return bool True if caution auto-reporting is enabled
         */
        public function isAutoReportCaution(): bool
        {
            return $this->autoReportCaution;
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
         * Returns the reputation window duration
         *
         * @return int Window duration in seconds
         */
        public function getReputationWindowDuration(): int
        {
            return $this->reputationWindowDuration;
        }

        /**
         * Returns the reputation gained by an entity for each window of clean, authenticated activity
         *
         * @return int Reputation gain per window
         */
        public function getReputationGain(): int
        {
            return $this->reputationGain;
        }

        /**
         * Returns the minimum reputation bound
         *
         * @return int Minimum bound
         */
        public function getReputationMinBound(): int
        {
            return $this->reputationMinBound;
        }

        /**
         * Returns the maximum reputation bound
         *
         * @return int Maximum bound
         */
        public function getReputationMaxBound(): int
        {
            return $this->reputationMaxBound;
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
            return $this->riskScoreNeutralPoint;
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
            return $this->riskScoreMinBound;
        }

        /**
         * Returns the maximum risk score bound
         *
         * @return float Maximum bound
         */
        public function getRiskScoreMaxBound(): float
        {
            return $this->riskScoreMaxBound;
        }
    }