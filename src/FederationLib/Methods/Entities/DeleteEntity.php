<?php

    namespace FederationLib\Methods\Entities;

    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;

    class DeleteEntity extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!$authenticatedOperator->canManageBlacklist())
            {
                throw new RequestException('Insufficient permissions to manage entities', 401);
            }

            if(
                !preg_match('#^/entities/([a-fA-F0-9\-]{36})/query$#', FederationServer::getPath(), $matches) &&
                !preg_match('#^/entities/([a-f0-9\-]{64})/query$#', FederationServer::getPath(), $matches)
            )
            {
                throw new RequestException('Entity UUID is required', 400);
            }

            $entityIdentifier = $matches[1];
            if(!$entityIdentifier)
            {
                throw new RequestException('Entity Identifier SHA-256/UUID is required', 400);
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

                EntitiesManager::deleteEntity($entityRecord->getUuid());
                AuditLogManager::createEntry(AuditLogType::ENTITY_DELETED, sprintf(
                    'Entity %s deleted by %s (%s)',
                    $entityRecord->getUuid(),
                    $authenticatedOperator->getName(),
                    $authenticatedOperator->getUuid()
                ), $authenticatedOperator->getUuid(), $entityRecord->getUuid());
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Unable to delete entity', 500, $e);
            }

            self::successResponse();
        }
    }

