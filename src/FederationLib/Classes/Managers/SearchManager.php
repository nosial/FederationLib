<?php

    namespace FederationLib\Classes\Managers;

    use FederationLib\Classes\Configuration;
    use FederationLib\Enums\RecordType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Objects\OperatorRecord;
    use FederationLib\Objects\SearchResult;

    class SearchManager
    {
        /**
         * Preforms a global search against all tables in the database
         *
         * @param string $query The search query to use
         * @param int $limit The limit of the search results
         * @param int $page The current page of the search results
         * @param OperatorRecord|null $operator Optional. The authenticated operator preforming the search
         * @param array|null $types Optional. The types to filter by
         * @return SearchResult[] Returns an array of SearchResult objects
         * @throws DatabaseOperationException Thrown if there was a database operation error
         */
        public static function search(string $query, int $limit, int $page, ?OperatorRecord $operator, ?array $types=null): array
        {
            $results = [];
            $searchAll = $types === null || $types === [];

            if (($searchAll || in_array(RecordType::ENTITY->value, $types, true)) && Configuration::getSearchConfiguration()->isEntitiesEnabled())
            {
                if (Configuration::getServerConfiguration()->isEntitiesPublic())
                {
                    array_push($results, ...array_map(
                        fn($r) => new SearchResult(RecordType::ENTITY, $r),
                        EntitiesManager::searchEntities($query, $limit, $page)
                    ));
                }
            }

            if (($searchAll || in_array(RecordType::EVIDENCE->value, $types, true)) && Configuration::getSearchConfiguration()->isEvidenceEnabled())
            {
                if (Configuration::getServerConfiguration()->isEvidencePublic() || $operator !== null)
                {
                    array_push($results, ...array_map(
                        fn($r) => new SearchResult(RecordType::EVIDENCE, $r),
                        EvidenceManager::searchEvidence($query, $limit, $page, $operator !== null && $operator->hasManagementPermissions())
                    ));
                }
            }

            if (($searchAll || in_array(RecordType::BLACKLIST->value, $types, true)) && Configuration::getSearchConfiguration()->isBlacklistEnabled())
            {
                if (Configuration::getServerConfiguration()->isBlacklistPublic())
                {
                    array_push($results, ...array_map(
                        fn($r) => new SearchResult(RecordType::BLACKLIST, $r),
                        BlacklistManager::searchBlacklist($query, $limit, $page)
                    ));
                }
            }

            if (($searchAll || in_array(RecordType::REPORT->value, $types, true)) && Configuration::getSearchConfiguration()->isReportsEnabled())
            {
                if (Configuration::getServerConfiguration()->isReportsPublic())
                {
                    array_push($results, ...array_map(
                        fn($r) => new SearchResult(RecordType::REPORT, $r),
                        ReportManager::searchReports($query, $limit, $page)
                    ));
                }
            }

            if (($searchAll || in_array(RecordType::ATTACHMENT->value, $types, true)) && Configuration::getSearchConfiguration()->isAttachmentsEnabled())
            {
                if (Configuration::getServerConfiguration()->isEvidencePublic())
                {
                    array_push($results, ...array_map(
                        fn($r) => new SearchResult(RecordType::ATTACHMENT, $r),
                        FileAttachmentManager::searchAttachments($query, $limit, $page, $operator !== null && $operator->hasManagementPermissions())
                    ));
                }
            }

            if (($searchAll || in_array(RecordType::AUDIT_LOG->value, $types, true)) && Configuration::getSearchConfiguration()->isAuditLogsEnabled())
            {
                if (Configuration::getServerConfiguration()->isAuditLogsPublic())
                {
                    array_push($results, ...array_map(
                        fn($r) => new SearchResult(RecordType::AUDIT_LOG, $r),
                        AuditLogManager::searchAuditLogs($query, $limit, $page, $operator !== null)
                    ));
                }
            }

            if (($searchAll || in_array(RecordType::OPERATOR->value, $types, true)) && Configuration::getSearchConfiguration()->isOperatorsEnabled())
            {
                if ($operator !== null)
                {
                    array_push($results, ...array_map(
                        fn($r) => new SearchResult(RecordType::OPERATOR, $r),
                        OperatorManager::searchOperators($query, $limit, $page, !$operator->hasOperatorPermissions())
                    ));
                }
            }

            return $results;
        }
    }
