<?php

    namespace FederationServer\Methods\Blacklist;

    use FederationServer\Classes\Configuration;
    use FederationServer\Classes\Enums\BlacklistType;
    use FederationServer\Classes\Managers\BlacklistManager;
    use FederationServer\Classes\Managers\EntitiesManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Classes\Validate;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;

    class BlacklistEntity extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            // Get the authenticated operator
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!$authenticatedOperator->canManageBlacklist())
            {
                throw new RequestException('Insufficient permissions to manage the blacklist', 401);
            }

            $entityUuid = FederationServer::getParameter('entity');
            $type = BlacklistType::tryFrom(FederationServer::getParameter('type'));
            $expires = FederationServer::getParameter('expires');
            $evidence = FederationServer::getParameter('evidence');

            if($entityUuid === null || !Validate::uuid($entityUuid))
            {
                throw new RequestException('A valid entity UUID is required', 400);
            }

            if($type === null)
            {
                throw new RequestException('A valid blacklist type is required', 400);
            }

            if($expires !== null)
            {
                if((int)$expires < time())
                {
                    throw new RequestException('The expiration time must be in the future', 400);
                }
                if((int)$expires < (time() + Configuration::getServerConfiguration()->getListBlacklistMaxItems()))
                {
                    throw new RequestException('The expiration time must be at least ' . Configuration::getServerConfiguration()->getListBlacklistMaxItems() . ' seconds in the future', 400);
                }
            }

            if($evidence !== null && !Validate::uuid($evidence))
            {
                throw new RequestException('Evidence must be a valid UUID', 400);
            }

            try
            {
                if(!EntitiesManager::entityExistsByUuid($entityUuid))
                {
                    throw new RequestException('Entity not found', 404);
                }

                if($evidence !== null && !EntitiesManager::entityExistsByUuid($evidence))
                {
                    throw new RequestException('Evidence entity not found', 404);
                }

                $blacklistUuid = BlacklistManager::blacklistEntity(
                    entity: $entityUuid,
                    operator: $authenticatedOperator->getUuid(),
                    type: $type,
                    expires: $expires,
                    evidence: $evidence
                );
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException('Failed to blacklist entity', 500, $e);
            }

            self::successResponse($blacklistUuid);
        }
    }