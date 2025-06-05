<?php

    namespace FederationServer\Methods\Entities;

    use FederationServer\Classes\Configuration;
    use FederationServer\Classes\Managers\EntitiesManager;
    use FederationServer\Classes\Managers\EvidenceManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;

    class ListEntityEvidence extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator(false);
            $includeConfidential = false;

            if(!Configuration::getServerConfiguration()->isEvidencePublic() && $authenticatedOperator === null)
            {
                throw new RequestException('Unauthorized: You must be authenticated to list evidence', 401);
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


            if(!preg_match('#^/entities/([a-fA-F0-9\-]{36,})/evidence$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Bad Request: Entity UUID is required', 400);
            }

            $entityUuid = $matches[1];
            if(!$entityUuid)
            {
                throw new RequestException('Bad Request: Entity UUID is required', 400);
            }

            try
            {
                $existingEntity = EntitiesManager::getEntityByUuid($entityUuid);
                if($existingEntity === null)
                {
                    throw new RequestException('Entity Not Found', 404);
                }
                
                $evidenceRecords = EvidenceManager::getEvidenceRecords($limit, $page, $includeConfidential);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Internal Server Error: Unable to retrieve evidence', 500, $e);
            }

            $result = array_map(fn($evidence) => $evidence->toArray(), $evidenceRecords);
            self::successResponse($result);
        }
    }

