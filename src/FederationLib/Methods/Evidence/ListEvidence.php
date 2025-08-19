<?php

    namespace FederationLib\Methods\Evidence;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;

    class ListEvidence extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if(!Configuration::getServerConfiguration()->isEvidencePublic() && $authenticatedOperator === null)
            {
                throw new RequestException('You must be authenticated to list evidence', 401);
            }

            if(FederationServer::getParameter('include_confidential') !== null && $authenticatedOperator->canManageBlacklist())
            {
                $includeConfidential = true;
            }
            else
            {
                $includeConfidential = false;
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
                $evidenceRecords = EvidenceManager::getEvidenceRecords($limit, $page, $includeConfidential);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Unable to retrieve evidence', 500, $e);
            }

            self::successResponse(array_map(fn($evidence) => $evidence->toArray(), $evidenceRecords));
        }
    }

