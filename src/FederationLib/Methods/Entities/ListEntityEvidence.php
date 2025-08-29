<?php

    namespace FederationLib\Methods\Entities;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Utilities;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;

    class ListEntityEvidence extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            $includeConfidential = false;

            if(!Configuration::getServerConfiguration()->isEvidencePublic() && $authenticatedOperator === null)
            {
                throw new RequestException('You must be authenticated to list evidence', 401);
            }

            if($authenticatedOperator !== null)
            {
                $includeConfidential = true;
            }

            $limit = (int) (FederationServer::getParameter('limit') ?? Configuration::getServerConfiguration()->getListEvidenceMaxItems());
            $page = (int) (FederationServer::getParameter('page') ?? 1);
            
            if($limit < 1 || $limit > Configuration::getServerConfiguration()->getListEvidenceMaxItems())
            {
                $limit = Configuration::getServerConfiguration()->getListEvidenceMaxItems();
            }

            if($page < 1)
            {
                $page = 1;
            }

            if(
                !preg_match('#^/entities/([a-fA-F0-9\-]{36})/evidence$#', FederationServer::getPath(), $matches) &&
                !preg_match('#^/entities/([a-f0-9\-]{64})/evidence$#', FederationServer::getPath(), $matches)
            )
            {
                throw new RequestException('Entity identifier is required', 400);
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
                
                $evidenceRecords = EvidenceManager::getEvidenceByEntity($entityRecord->getUuid(), $limit, $page, $includeConfidential);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Unable to retrieve evidence', 500, $e);
            }

            self::successResponse(array_map(fn($evidence) => $evidence->toArray(), $evidenceRecords));
        }
    }

