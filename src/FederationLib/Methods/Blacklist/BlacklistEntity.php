<?php

    namespace FederationLib\Methods\Blacklist;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\BlacklistType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;

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

                AuditLogManager::createEntry(AuditLogType::ENTITY_BLACKLISTED, sprintf(
                    'Entity %s blacklisted by %s (%s) with type %s%s',
                    $entityUuid,
                    $authenticatedOperator->getName(),
                    $authenticatedOperator->getUuid(),
                    $type->name,
                    $expires ? ' until ' . date('Y-m-d H:i:s', $expires) : ''
                ), $authenticatedOperator->getUuid(), $entityUuid);
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException('Failed to blacklist entity', 500, $e);
            }

            self::successResponse($blacklistUuid);
        }
    }