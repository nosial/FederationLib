<?php

    namespace FederationServer\Methods\Evidence;

    use FederationServer\Classes\Configuration;
    use FederationServer\Classes\Managers\EvidenceManager;
    use FederationServer\Classes\Managers\OperatorManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;

    class ListEvidence extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator(false);
            if(!Configuration::getServerConfiguration()->isPublicEvidence() && $authenticatedOperator === null)
            {
                throw new RequestException('Unauthorized: You must be authenticated to list evidence', 401);
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

            try
            {
                $operators = EvidenceManager::getEvidenceRecords($limit, $page);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Internal Server Error: Unable to retrieve operators', 500, $e);
            }

            $result = array_map(fn($op) => $op->toArray(), $operators);
            self::successResponse($result);
        }
    }

