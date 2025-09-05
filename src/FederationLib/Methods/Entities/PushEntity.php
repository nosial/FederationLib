<?php

    namespace FederationLib\Methods\Entities;

    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use InvalidArgumentException;

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

            $domain = FederationServer::getParameter('domain');
            $id = FederationServer::getParameter('id') ?? null;

            try
            {
                if(!EntitiesManager::entityExists($domain, $id))
                {
                    $entityUuid = EntitiesManager::registerEntity($domain, $id);
                    AuditLogManager::createEntry(AuditLogType::ENTITY_PUSHED, sprintf(
                        'Entity %s registered by %s (%s)',
                        $id,
                        $authenticatedOperator->getName(),
                        $authenticatedOperator->getUuid()
                    ), $authenticatedOperator->getUuid(), $entityUuid);
                }
                else
                {
                    $entityUuid = EntitiesManager::getEntity($domain, $id)->getUuid();
                }
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Unable to register entity', 500, $e);
            }

            self::successResponse($entityUuid);
        }
    }

