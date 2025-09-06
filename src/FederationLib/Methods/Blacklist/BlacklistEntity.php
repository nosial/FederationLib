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
    use FederationLib\Enums\HttpResponseCode;
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
                throw new RequestException('Insufficient permissions to manage the blacklist', HttpResponseCode::FORBIDDEN);
            }

            // TODO: All forms of entities should be both identifiable as their UUID and SHA256 hash
            $entityUuid = FederationServer::getParameter('entity_uuid');
            $type = BlacklistType::tryFrom(FederationServer::getParameter('type') ?? '');
            $expires = FederationServer::getParameter('expires');
            $evidence = FederationServer::getParameter('evidence_uuid') ?? null;

            if($entityUuid !== null && !Validate::uuid($entityUuid))
            {
                throw new RequestException('A valid entity UUID is required', HttpResponseCode::BAD_REQUEST);
            }

            if($type === null)
            {
                throw new RequestException('A valid blacklist type is required', HttpResponseCode::BAD_REQUEST);
            }

            if($expires !== null)
            {
                if((int)$expires < time())
                {
                    throw new RequestException('The expiration time must be in the future', HttpResponseCode::BAD_REQUEST);
                }
            }

            if($evidence !== null && !Validate::uuid($evidence))
            {
                throw new RequestException('Evidence must be a valid UUID', HttpResponseCode::BAD_REQUEST);
            }

            try
            {
                if(!EntitiesManager::entityExistsByUuid($entityUuid))
                {
                    throw new RequestException(sprintf("Entity UUID %s not found", $entityUuid), HttpResponseCode::NOT_FOUND);
                }

                if($evidence !== null && !EntitiesManager::entityExistsByUuid($evidence))
                {
                    throw new RequestException('Evidence entity not found', HttpResponseCode::NOT_FOUND);
                }

                $blacklistUuid = BlacklistManager::blacklistEntity(
                    entityUuid: $entityUuid,
                    operator_uuid: $authenticatedOperator->getUuid(),
                    type: $type,
                    expires: $expires,
                    evidenceUuid: $evidence
                );

                AuditLogManager::createEntry(AuditLogType::ENTITY_BLACKLISTED, sprintf(
                    'Entity %s blacklisted by %s (%s) with type %s%s',
                    $entityUuid,
                    $authenticatedOperator->getName(),
                    $authenticatedOperator->getUuid(),
                    $type->name,
                    $expires ? ' until ' . date('Y-m-d H:i:s', $expires) : ' as a permanent'
                ), $authenticatedOperator->getUuid(), $entityUuid);
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException('Failed to blacklist entity', 500, $e);
            }

            self::successResponse($blacklistUuid);
        }
    }