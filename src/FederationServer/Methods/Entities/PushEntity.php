<?php

    namespace FederationServer\Methods\Entities;

    use FederationServer\Classes\Enums\AuditLogType;
    use FederationServer\Classes\Managers\AuditLogManager;
    use FederationServer\Classes\Managers\EntitiesManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;

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

            $id = FederationServer::getParameter('id');
            $domain = FederationServer::getParameter('domain') ?? null;

            if(!$id)
            {
                throw new RequestException('Entity ID is required', 400);
            }

            if(strlen($id) > 255)
            {
                throw new RequestException('Entity ID exceeds maximum length of 255 characters', 400);
            }

            if(!is_null($domain) && !filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))
            {
                throw new RequestException('Invalid domain format', 400);
            }

            if(!is_null($domain) && strlen($domain) > 255)
            {
                throw new RequestException('Domain exceeds maximum length of 255 characters', 400);
            }

            try
            {
                if(!EntitiesManager::entityExists($id, $domain))
                {
                    $entityUuid = EntitiesManager::registerEntity($id, $domain);
                    AuditLogManager::createEntry(AuditLogType::ENTITY_PUSHED, sprintf(
                        'Entity %s registered by %s (%s)',
                        $id,
                        $authenticatedOperator->getName(),
                        $authenticatedOperator->getUuid()
                    ), $authenticatedOperator->getUuid(), $entityUuid);
                }
                else
                {
                    $entityUuid = EntitiesManager::getEntity($id, $domain)->getUuid();
                }
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Unable to register entity', 500, $e);
            }

            self::successResponse($entityUuid);
        }
    }

