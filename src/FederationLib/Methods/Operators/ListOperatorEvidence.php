<?php

    namespace FederationLib\Methods\Operators;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;

    class ListOperatorEvidence extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            $listConfidential = false;

            if(!Configuration::getServerConfiguration()->isEvidencePublic() && $authenticatedOperator === null)
            {
                throw new RequestException('You must be authenticated to list evidence', 401);
            }

            if($authenticatedOperator !== null)
            {
                $listConfidential = true;
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


            if(!preg_match('#^/operators/([a-fA-F0-9\-]{36})/evidence$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Operator UUID is required', 400);
            }

            $operatorUuid = $matches[1];
            if(!$operatorUuid)
            {
                throw new RequestException('Operator UUID is required', 400);
            }

            try
            {
                if(!OperatorManager::operatorExists($operatorUuid))
                {
                    throw new RequestException('Operator Not Found', 404);
                }

                $evidenceRecords = EvidenceManager::getEvidenceByOperator($operatorUuid, $limit, $page, $listConfidential);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Unable to retrieve evidence', 500, $e);
            }

            self::successResponse(array_map(fn($evidence) => $evidence->toArray(), $evidenceRecords));
        }
    }

