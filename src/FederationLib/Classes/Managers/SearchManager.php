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
         * @param OperatorRecord|null $operator Optional. The authenticated operator performing the search
         * @param array|null $types Optional. The types to filter by
         * @return SearchResult[] Returns an array of SearchResult objects
         * @throws DatabaseOperationException Thrown if there was a database operation error
         */
        public static function search(string $query, int $limit, int $page, ?OperatorRecord $operator, ?array $types=null): array
        {
            $results = [];
            $searchAll = $types === null || $types === [];
            $extended = $operator !== null && Configuration::getSearchConfiguration()->isExtendedSearchEnabled();
            $hideTelephone = $operator === null && !Configuration::getServerConfiguration()->isTelephoneEntitiesPublic();

            if (($searchAll || in_array(RecordType::ENTITY->value, $types, true)) && Configuration::getSearchConfiguration()->isEntitiesEnabled())
            {
                // Records of a domain are returned according to its accessibility, operators can read every domain
                if (Configuration::getServerConfiguration()->isEntitiesPublic() || $operator !== null)
                {
                    array_push($results, ...array_map(
                        fn($r) => new SearchResult(RecordType::ENTITY, $r),
                        EntitiesManager::searchEntities($query, $limit, $page, extended: $extended, excludeTelephone: $hideTelephone)
                    ));
                }
            }

            if (($searchAll || in_array(RecordType::EVIDENCE->value, $types, true)) && Configuration::getSearchConfiguration()->isEvidenceEnabled())
            {
                if (Configuration::getServerConfiguration()->isEvidencePublic() || $operator !== null)
                {
                    array_push($results, ...array_map(
                        fn($r) => new SearchResult(RecordType::EVIDENCE, $r),
                        EvidenceManager::searchEvidence($query, $limit, $page, $operator !== null && $operator->hasManagementPermissions(), extended: $extended)
                    ));
                }
            }

            if (($searchAll || in_array(RecordType::BLACKLIST->value, $types, true)) && Configuration::getSearchConfiguration()->isBlacklistEnabled())
            {
                // Records of a domain are returned according to its accessibility, operators can read every domain
                if (Configuration::getServerConfiguration()->isBlacklistPublic() || $operator !== null)
                {
                    array_push($results, ...array_map(
                        fn($r) => new SearchResult(RecordType::BLACKLIST, $r),
                        BlacklistManager::searchBlacklist($query, $limit, $page, extended: $extended, excludeTelephone: $hideTelephone)
                    ));
                }
            }

            if (($searchAll || in_array(RecordType::REPORT->value, $types, true)) && Configuration::getSearchConfiguration()->isReportsEnabled())
            {
                // Records of a domain are returned according to its accessibility, operators can read every domain
                if (Configuration::getServerConfiguration()->isReportsPublic() || $operator !== null)
                {
                    array_push($results, ...array_map(
                        fn($r) => new SearchResult(RecordType::REPORT, $r),
                        ReportManager::searchReports($query, $limit, $page, extended: $extended)
                    ));
                }
            }

            if (($searchAll || in_array(RecordType::ATTACHMENT->value, $types, true)) && Configuration::getSearchConfiguration()->isAttachmentsEnabled())
            {
                // File attachments are returned only to operators with management permissions, as Search Attachments does
                if ($operator !== null && $operator->hasManagementPermissions())
                {
                    array_push($results, ...array_map(
                        fn($r) => new SearchResult(RecordType::ATTACHMENT, $r),
                        FileAttachmentManager::searchAttachments($query, $limit, $page, true, extended: $extended)
                    ));
                }
            }

            if (($searchAll || in_array(RecordType::AUDIT_LOG->value, $types, true)) && Configuration::getSearchConfiguration()->isAuditLogsEnabled())
            {
                // Records of a domain are returned according to its accessibility, operators can read every domain
                if (Configuration::getServerConfiguration()->isAuditLogsPublic() || $operator !== null)
                {
                    array_push($results, ...array_map(
                        fn($r) => new SearchResult(RecordType::AUDIT_LOG, $r),
                        AuditLogManager::searchAuditLogs($query, $limit, $page, $operator !== null, extended: $extended)
                    ));
                }
            }

            if (($searchAll || in_array(RecordType::OPERATOR->value, $types, true)) && Configuration::getSearchConfiguration()->isOperatorsEnabled())
            {
                // Operator records are public information, so they're returned to every requester
                array_push($results, ...array_map(
                    fn($r) => new SearchResult(RecordType::OPERATOR, $r),
                    OperatorManager::searchOperators($query, $limit, $page, $operator === null || !$operator->hasOperatorPermissions())
                ));
            }

            return $results;
        }
    }
