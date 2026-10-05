<?php

    namespace FederationLib\Interfaces;

    use FederationLib\Exceptions\EntityQueryRejectedException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\Objects\Plugin\EntityQuery;

    interface QueryEntityEventHandlerInterface
    {
        /**
         * Handles a query entity request, executed after FederationLib resolved the entity, its relationship group
         * and the active blacklists and before the response is sent.
         *
         * The handler has access to the request and the result through the EntityQuery object, and may optionally:
         *
         *  - Add or remove related entities with EntityQuery::addRelatedEntity() and removeRelatedEntity()
         *  - Add or remove active blacklist records with EntityQuery::addBlacklist() and removeBlacklist(), the
         *    suggested action is derived from them
         *  - Add metadata to the queried entity with EntityQuery::addEntityMetadata()
         *  - Override the suggested action with EntityQuery::setSuggestedAction()
         *  - Reject the request with EntityQuery::reject() (or by throwing a RequestException), the client receives
         *    an error response
         *
         * The changes only affect the response, nothing is written to the database. Any other exception thrown by the
         * handler is logged and the response is sent without the changes the handler made.
         *
         * @param EntityQuery $entityQuery The query entity request
         * @return void
         * @throws EntityQueryRejectedException|RequestException If the query entity request is rejected
         */
        public static function handleQueryEntity(EntityQuery $entityQuery): void;
    }
