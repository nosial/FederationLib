<?php

    namespace FederationLib\Methods\Entities;

    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;

    class PushEntity extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!$authenticatedOperator->isClient())
            {
                throw new RequestException('Insufficient permissions to push entities', 403);
            }

            $host = FederationServer::getParameter('host');
            $id = FederationServer::getParameter('id') ?? null;
            $metadata = FederationServer::getParameter('metadata');

            if($metadata !== null && (!is_array($metadata) || !Validate::entityMetadata($metadata)))
            {
                throw new RequestException('Invalid entity metadata provided', 400);
            }

            try
            {
                if(!EntitiesManager::entityExists($host, $id))
                {
                    $entityUuid = EntitiesManager::registerEntity($host, $id, $metadata);
                    AuditLogManager::createEntry(AuditLogType::ENTITY_PUSHED, sprintf(
                        'Entity %s registered by operator %s',
                        $id,
                        $authenticatedOperator->getName()
                    ), $authenticatedOperator->getUuid(), $entityUuid);
                }
                else
                {
                    $entity = EntitiesManager::getEntity($host, $id);
                    $entityUuid = $entity->getUuid();

                    if($metadata !== null && EntitiesManager::updateEntityMetadata($entityUuid, $metadata))
                    {
                        AuditLogManager::createEntry(AuditLogType::ENTITY_UPDATED, sprintf(
                            'Entity %s updated by operator %s',
                            $entity->getAddress(), $authenticatedOperator->getName()
                        ), $authenticatedOperator->getUuid(), $entityUuid);
                    }
                }
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Unable to register entity', 500, $e);
            }

            self::successResponse($entityUuid);
        }
    }

