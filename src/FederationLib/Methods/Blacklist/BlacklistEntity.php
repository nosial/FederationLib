<?php

    namespace FederationLib\Methods\Blacklist;

    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Utilities;
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

            $entityIdentifier = FederationServer::getParameter('entity_identifier') ?? null;
            $evidence = FederationServer::getParameter('evidence_uuid') ?? null;
            $type = BlacklistType::tryFrom(FederationServer::getParameter('type') ?? '');
            $expires = FederationServer::getParameter('expires');

            if($entityIdentifier === null)
            {
                throw new RequestException('Entity UUID is required', HttpResponseCode::BAD_REQUEST);
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
                if(Utilities::isUuid($entityIdentifier))
                {
                    $entityRecord = EntitiesManager::getEntityByUuid($entityIdentifier);
                }
                elseif(Utilities::isSha256($entityIdentifier))
                {
                    $entityRecord = EntitiesManager::getEntityByHash($entityIdentifier);
                }
                else
                {
                    throw new RequestException('Given identifier is not a valid UUID or SHA-256 input', 400);
                }

                if($entityRecord === null)
                {
                    throw new RequestException('Entity not found', HttpResponseCode::NOT_FOUND);
                }

                if($evidence !== null && !EntitiesManager::entityExistsByUuid($evidence))
                {
                    throw new RequestException('Evidence entity not found', HttpResponseCode::NOT_FOUND);
                }

                $blacklistUuid = BlacklistManager::blacklistEntity(
                    entityUuid: $entityRecord->getUuid(),
                    operatorUuid: $authenticatedOperator->getUuid(),
                    type: $type,
                    expires: $expires,
                    evidenceUuid: $evidence
                );

                AuditLogManager::createEntry(AuditLogType::ENTITY_BLACKLISTED, sprintf(
                    'Entity %s blacklisted by %s (%s) with type %s%s',
                    $entityRecord->getAddress(),
                    $authenticatedOperator->getName(),
                    $authenticatedOperator->getUuid(),
                    $type->name,
                    $expires ? ' until ' . date('Y-m-d H:i:s', $expires) : ' as a permanent'
                ), $authenticatedOperator->getUuid(), $entityIdentifier);
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException('Failed to blacklist entity', 500, $e);
            }

            self::successResponse($blacklistUuid, HttpResponseCode::CREATED);
        }
    }