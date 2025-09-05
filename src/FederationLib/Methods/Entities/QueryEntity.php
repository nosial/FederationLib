<?php

    namespace FederationLib\Methods\Entities;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Utilities;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use InvalidArgumentException;

    class QueryEntity extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if(!Configuration::getServerConfiguration()->isEntitiesPublic() && $authenticatedOperator === null)
            {
                throw new RequestException('You must be authenticated to query entity records', 401);
            }

            if(
                !preg_match('#^/entities/([a-fA-F0-9\-]{36})/query$#', FederationServer::getPath(), $matches) &&
                !preg_match('#^/entities/([a-f0-9\-]{64})/query$#', FederationServer::getPath(), $matches)
            )
            {
                throw new RequestException('Entity identifier is required', 400);
            }

            $entityIdentifier = $matches[1];
            if(!$entityIdentifier)
            {
                throw new RequestException('Entity Identifier SHA-256/UUID is required', 400);
            }

            if(FederationServer::getParameter('include_confidential') !== null && $authenticatedOperator->canManageBlacklist())
            {
                $includeConfidential = true;
            }
            else
            {
                $includeConfidential = false;
            }

            if(FederationServer::getParameter('include_lifted') !== null && $authenticatedOperator->canManageBlacklist())
            {
                $includeLifted = true;
            }
            else
            {
                $includeLifted = false;
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
                    throw new RequestException('Entity not found', 404);
                }

                $queriedEntity = EntitiesManager::queryEntity($entityRecord, $includeConfidential, $includeLifted);
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException('Unable to query entity', 500, $e);
            }

            self::successResponse($queriedEntity);
        }
    }