<?php

    namespace FederationLib\Classes\Configuration;

    use FederationLib\Enums\ScanningRules;

    class ScanningConfiguration
    {
        private float $defaultScore;
        private float $trustScoreSteepness;
        private int $reputationUpdateInterval;
        private int $goodReputationThreshold;
        private int $badReputationThreshold;
        private float $authorBlacklisted;
        private float $authorPermanentlyBlacklisted;
        private float $authorWhitelisted;
        private float $authorGoodReputation;
        private float $authorBadReputation;
        private float $authorParentBlacklisted;
        private float $authorParentPermanentlyBlacklisted;
        private float $authorParentWhitelisted;
        private float $authorParentGoodReputation;
        private float $authorParentBadReputation;
        private float $namedEntityBlacklisted;
        private float $namedEntityPermanentlyBlacklisted;
        private float $namedEntityWhitelisted;
        private float $namedEntityGoodReputation;
        private float $namedEntityBadReputation;
        private float $namedEntityParentBlacklisted;
        private float $namedEntityParentPermanentlyBlacklisted;
        private float $namedEntityParentWhitelisted;
        private float $namedEntityParentGoodReputation;
        private float $namedEntityParentBadReputation;
        private float $classificationNormal;
        private float $classificationSuspicious;
        private float $classificationMalicious;
        private bool $autoReport;
        private float $autoReportThreshold;
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
            $this->defaultScore = (float)($configuration['default_score'] ?? 0.0);
            $this->trustScoreSteepness = (float)($configuration['trust_score_steepness'] ?? 0.25);
            $this->reputationUpdateInterval = (int)($configuration['reputation_update_interval'] ?? 900);
            $this->goodReputationThreshold = (int)($configuration['good_reputation_threshold'] ?? 50);
            $this->badReputationThreshold = (int)($configuration['bad_reputation_threshold'] ?? -50);
            $this->authorBlacklisted = (float)($configuration['author_blacklisted'] ?? ScanningRules::AUTHOR_BLACKLISTED->getModifier());
            $this->authorPermanentlyBlacklisted = (float)($configuration['author_permanently_blacklisted'] ?? ScanningRules::AUTHOR_PERMANENTLY_BLACKLISTED->getModifier());
            $this->authorWhitelisted = (float)($configuration['author_whitelisted'] ?? ScanningRules::AUTHOR_WHITELISTED->getModifier());
            $this->namedEntityBlacklisted = (float)($configuration['named_entity_blacklisted'] ?? ScanningRules::NAMED_ENTITY_BLACKLISTED->getModifier());
            $this->namedEntityPermanentlyBlacklisted = (float)($configuration['named_entity_permanently_blacklisted'] ?? ScanningRules::NAMED_ENTITY_PERMANENTLY_BLACKLISTED->getModifier());
            $this->namedEntityWhitelisted = (float)($configuration['named_entity_whitelisted'] ?? ScanningRules::NAMED_ENTITY_WHITELISTED->getModifier());
            $this->authorBadReputation = (float)($configuration['author_bad_reputation'] ?? ScanningRules::AUTHOR_BAD_REPUTATION->getModifier());
            $this->authorGoodReputation = (float)($configuration['author_good_reputation'] ?? ScanningRules::AUTHOR_GOOD_REPUTATION->getModifier());
            $this->authorParentBlacklisted = (float)($configuration['author_parent_blacklisted'] ?? ScanningRules::AUTHOR_PARENT_BLACKLISTED->getModifier());
            $this->authorParentPermanentlyBlacklisted = (float)($configuration['author_parent_permanently_blacklisted'] ?? ScanningRules::AUTHOR_PARENT_PERMANENTLY_BLACKLISTED->getModifier());
            $this->authorParentWhitelisted = (float)($configuration['author_parent_whitelisted'] ?? ScanningRules::AUTHOR_PARENT_WHITELISTED->getModifier());
            $this->authorParentGoodReputation = (float)($configuration['author_parent_good_reputation'] ?? ScanningRules::AUTHOR_PARENT_GOOD_REPUTATION->getModifier());
            $this->authorParentBadReputation = (float)($configuration['author_parent_bad_reputation'] ?? ScanningRules::AUTHOR_PARENT_BAD_REPUTATION->getModifier());
            $this->namedEntityBadReputation = (float)($configuration['named_entity_bad_reputation'] ?? ScanningRules::NAMED_ENTITY_BAD_REPUTATION->getModifier());
            $this->namedEntityGoodReputation = (float)($configuration['named_entity_good_reputation'] ?? ScanningRules::NAMED_ENTITY_GOOD_REPUTATION->getModifier());
            $this->namedEntityParentBlacklisted = (float)($configuration['named_entity_parent_blacklisted'] ?? ScanningRules::NAMED_ENTITY_PARENT_BLACKLISTED->getModifier());
            $this->namedEntityParentPermanentlyBlacklisted = (float)($configuration['named_entity_parent_permanently_blacklisted'] ?? ScanningRules::NAMED_ENTITY_PARENT_PERMANENTLY_BLACKLISTED->getModifier());
            $this->namedEntityParentWhitelisted = (float)($configuration['named_entity_parent_whitelisted'] ?? ScanningRules::NAMED_ENTITY_PARENT_WHITELISTED->getModifier());
            $this->namedEntityParentGoodReputation = (float)($configuration['named_entity_parent_good_reputation'] ?? ScanningRules::NAMED_ENTITY_PARENT_GOOD_REPUTATION->getModifier());
            $this->namedEntityParentBadReputation = (float)($configuration['named_entity_parent_bad_reputation'] ?? ScanningRules::NAMED_ENTITY_PARENT_BAD_REPUTATION->getModifier());
            $this->classificationNormal = (float)($configuration['classification_normal'] ?? ScanningRules::CLASSIFICATION_NORMAL->getModifier());
            $this->classificationSuspicious = (float)($configuration['classification_suspicious'] ?? ScanningRules::CLASSIFICATION_SUSPICIOUS->getModifier());
            $this->classificationMalicious = (float)($configuration['classification_malicious'] ?? ScanningRules::CLASSIFICATION_MALICIOUS->getModifier());
            $this->autoReport = (bool)($configuration['auto_report'] ?? false);
            $this->autoReportThreshold = (float)($configuration['auto_report_threshold'] ?? 40.00);
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
         * Returns the default score
         *
         * @return float Default score value
         */
        public function getDefaultScore(): float
        {
            return $this->defaultScore;
        }

        /**
         * Returns the trust score steepness
         *
         * @return float Trust score steepness
         */
        public function getTrustScoreSteepness(): float
        {
            return $this->trustScoreSteepness;
        }

        /**
         * Returns the reputation update interval
         *
         * @return int Update interval in seconds
         */
        public function getReputationUpdateInterval(): int
        {
            return $this->reputationUpdateInterval;
        }

        /**
         * Returns the bad reputation threshold
         *
         * @return int Bad reputation threshold
         */
        public function getBadReputationThreshold(): int
        {
            return $this->badReputationThreshold;
        }

        /**
         * Returns the good reputation threshold
         *
         * @return int Good reputation threshold
         */
        public function getGoodReputationThreshold(): int
        {
            return $this->goodReputationThreshold;
        }

        /**
         * Returns the author blacklisted score modifier
         *
         * @return float Score modifier
         */
        public function getAuthorBlacklisted(): float
        {
            return $this->authorBlacklisted;
        }

        /**
         * Returns the author permanently blacklisted score modifier
         *
         * @return float Score modifier
         */
        public function getAuthorPermanentlyBlacklisted(): float
        {
            return $this->authorPermanentlyBlacklisted;
        }

        /**
         * Returns the author whitelisted score modifier
         *
         * @return float Score modifier
         */
        public function getAuthorWhitelisted(): float
        {
            return $this->authorWhitelisted;
        }

        /**
         * Returns the author good reputation score modifier
         *
         * @return float Score modifier
         */
        public function getAuthorGoodReputation(): float
        {
            return $this->authorGoodReputation;
        }

        /**
         * Returns the author bad reputation score modifier
         *
         * @return float Score modifier
         */
        public function getAuthorBadReputation(): float
        {
            return $this->authorBadReputation;
        }

        /**
         * Returns the named entity blacklisted score modifier
         *
         * @return float Score modifier
         */
        public function getNamedEntityBlacklisted(): float
        {
            return $this->namedEntityBlacklisted;
        }

        /**
         * Returns the named entity permanently blacklisted score modifier
         *
         * @return float Score modifier
         */
        public function getNamedEntityPermanentlyBlacklisted(): float
        {
            return $this->namedEntityPermanentlyBlacklisted;
        }

        /**
         * Returns the named entity whitelisted score modifier
         *
         * @return float Score modifier
         */
        public function getNamedEntityWhitelisted(): float
        {
            return $this->namedEntityWhitelisted;
        }

        /**
         * Returns the named entity good reputation score modifier
         *
         * @return float Score modifier
         */
        public function getNamedEntityGoodReputation(): float
        {
            return $this->namedEntityGoodReputation;
        }

        /**
         * Returns the named entity bad reputation score modifier
         *
         * @return float Score modifier
         */
        public function getNamedEntityBadReputation(): float
        {
            return $this->namedEntityBadReputation;
        }

        /**
         * Returns the author parent blacklisted score modifier
         *
         * @return float Score modifier
         */
        public function getAuthorParentBlacklisted(): float
        {
            return $this->authorParentBlacklisted;
        }

        /**
         * Returns the author parent permanently blacklisted score modifier
         *
         * @return float Score modifier
         */
        public function getAuthorParentPermanentlyBlacklisted(): float
        {
            return $this->authorParentPermanentlyBlacklisted;
        }

        /**
         * Returns the author parent whitelisted score modifier
         *
         * @return float Score modifier
         */
        public function getAuthorParentWhitelisted(): float
        {
            return $this->authorParentWhitelisted;
        }

        /**
         * Returns the author parent good reputation score modifier
         *
         * @return float Score modifier
         */
        public function getAuthorParentGoodReputation(): float
        {
            return $this->authorParentGoodReputation;
        }

        /**
         * Returns the author parent bad reputation score modifier
         *
         * @return float Score modifier
         */
        public function getAuthorParentBadReputation(): float
        {
            return $this->authorParentBadReputation;
        }

        /**
         * Returns the named entity parent blacklisted score modifier
         *
         * @return float Score modifier
         */
        public function getNamedEntityParentBlacklisted(): float
        {
            return $this->namedEntityParentBlacklisted;
        }

        /**
         * Returns the named entity parent permanently blacklisted score modifier
         *
         * @return float Score modifier
         */
        public function getNamedEntityParentPermanentlyBlacklisted(): float
        {
            return $this->namedEntityParentPermanentlyBlacklisted;
        }

        /**
         * Returns the named entity parent whitelisted score modifier
         *
         * @return float Score modifier
         */
        public function getNamedEntityParentWhitelisted(): float
        {
            return $this->namedEntityParentWhitelisted;
        }

        /**
         * Returns the named entity parent good reputation score modifier
         *
         * @return float Score modifier
         */
        public function getNamedEntityParentGoodReputation(): float
        {
            return $this->namedEntityParentGoodReputation;
        }

        /**
         * Returns the named entity parent bad reputation score modifier
         *
         * @return float Score modifier
         */
        public function getNamedEntityParentBadReputation(): float
        {
            return $this->namedEntityParentBadReputation;
        }

        /**
         * Returns the classification normal score modifier
         *
         * @return float Score modifier
         */
        public function getClassificationNormal(): float
        {
            return $this->classificationNormal;
        }

        /**
         * Returns the classification suspicious score modifier
         *
         * @return float Score modifier
         */
        public function getClassificationSuspicious(): float
        {
            return $this->classificationSuspicious;
        }

        /**
         * Returns the classification malicious score modifier
         *
         * @return float Score modifier
         */
        public function getClassificationMalicious(): float
        {
            return $this->classificationMalicious;
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