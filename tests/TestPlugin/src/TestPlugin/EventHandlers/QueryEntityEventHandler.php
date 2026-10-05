<?php

    namespace TestPlugin\EventHandlers;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\SuggestedActionType;
    use FederationLib\Interfaces\QueryEntityEventHandlerInterface;
    use FederationLib\Objects\Plugin\EntityQuery;
    use RuntimeException;

    /**
     * A QUERY_ENTITY event handler that only acts on entities whose host contains one of its trigger labels, so that
     * it never affects the queries of the other test units:
     *
     *  - test-plugin-reject: rejects the request (451)
     *  - test-plugin-enrich: adds the identifier it received and the number of related entities and active
     *    blacklists to the entity metadata (test_plugin_identifier, test_plugin_related, test_plugin_blacklists)
     *  - test-plugin-block: suggests a temporary block until 2100-01-01 (4102444800)
     *  - test-plugin-unblock: suggests no action, regardless of the active blacklists
     *  - test-plugin-isolate: removes every related entity (and their active blacklists)
     *  - test-plugin-fail: adds metadata and suggests a permanent block and then throws, both must be discarded
     */
    class QueryEntityEventHandler implements QueryEntityEventHandlerInterface
    {
        public const int BLOCK_LIFT_TIMESTAMP = 4102444800;

        /**
         * @inheritDoc
         */
        public static function handleQueryEntity(EntityQuery $entityQuery): void
        {
            $host = $entityQuery->getEntityRecord()->getHost();

            if(str_contains($host, 'test-plugin-reject'))
            {
                $entityQuery->reject('Rejected by the TestPlugin', HttpResponseCode::UNAVAILABLE_FOR_LEGAL_REASONS);
            }

            if(str_contains($host, 'test-plugin-enrich'))
            {
                $entityQuery->addEntityMetadata([
                    'test_plugin_identifier' => $entityQuery->getIdentifier(),
                    'test_plugin_related' => count($entityQuery->getRelatedEntities()),
                    'test_plugin_blacklists' => count($entityQuery->getActiveBlacklists()),
                ]);
            }

            if(str_contains($host, 'test-plugin-block'))
            {
                $entityQuery->setSuggestedAction(SuggestedActionType::TEMPORARILY_BLOCK_ENTITY, self::BLOCK_LIFT_TIMESTAMP);
            }

            if(str_contains($host, 'test-plugin-unblock'))
            {
                $entityQuery->setSuggestedAction(null);
            }

            if(str_contains($host, 'test-plugin-isolate'))
            {
                foreach($entityQuery->getRelatedEntities() as $relatedEntity)
                {
                    $entityQuery->removeRelatedEntity($relatedEntity->getUuid());
                }
            }

            if(str_contains($host, 'test-plugin-fail'))
            {
                $entityQuery->addEntityMetadata(['test_plugin_failed' => true]);
                $entityQuery->setSuggestedAction(SuggestedActionType::PERMANENTLY_BLOCK_ENTITY);
                throw new RuntimeException('QueryEntityEventHandler intentionally failed');
            }
        }
    }
