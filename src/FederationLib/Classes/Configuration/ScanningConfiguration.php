<?php

    namespace FederationLib\Classes\Configuration;

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
        private float $actionBlockThreshold;
        private float $actionCautionThreshold;
        private int $reputationWindowDuration;
        private int $reputationMaxDelta;
        private int $reputationMinDelta;
        private float $reputationScalingFactor;
        private int $reputationMinBound;
        private int $reputationMaxBound;
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
            $this->actionBlockThreshold = (float)($configuration['action_block_threshold'] ?? 80.00);
            $this->actionCautionThreshold = (float)($configuration['action_caution_threshold'] ?? 60.00);
            $this->reputationWindowDuration = (int)($configuration['reputation_window_duration'] ?? 300);
            $this->reputationMaxDelta = (int)($configuration['reputation_max_delta'] ?? 10);
            $this->reputationMinDelta = (int)($configuration['reputation_min_delta'] ?? -10);
            $this->reputationScalingFactor = (float)($configuration['reputation_scaling_factor'] ?? 0.25);
            $this->reputationMinBound = (int)($configuration['reputation_min_bound'] ?? -1000);
            $this->reputationMaxBound = (int)($configuration['reputation_max_bound'] ?? 1000);
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
         * Returns the maximum reputation delta
         *
         * @return int Maximum delta
         */
        public function getReputationMaxDelta(): int
        {
            return $this->reputationMaxDelta;
        }

        /**
         * Returns the minimum reputation delta
         *
         * @return int Minimum delta
         */
        public function getReputationMinDelta(): int
        {
            return $this->reputationMinDelta;
        }

        /**
         * Returns the reputation scaling factor
         *
         * @return float Scaling factor
         */
        public function getReputationScalingFactor(): float
        {
            return $this->reputationScalingFactor;
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