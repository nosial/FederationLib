<?php

    namespace FederationLib\Methods\Evidence;

    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;

    class UpdateConfidentiality extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!$authenticatedOperator->canManageBlacklist())
            {
                throw new RequestException('You do not have permission to update confidentiality settings', 403);
            }

            if(!preg_match('#^/evidence/([a-fA-F0-9\-]{36})/update_confidentiality$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Evidence UUID required', 405);
            }

            $evidenceUuid = $matches[1];
            if(!$evidenceUuid || !Validate::uuid($evidenceUuid))
            {
                throw new RequestException('Invalid evidence UUID', 400);
            }

            $confidential = (bool)FederationServer::getParameter('confidential') ?? false;

            try
            {
                $evidenceRecord = EvidenceManager::getEvidence($evidenceUuid);
                if($evidenceRecord === null)
                {
                    throw new RequestException('Evidence Not Found', 404);
                }

                EvidenceManager::updateConfidentiality($evidenceUuid, $confidential);
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException('Unable to get evidence', 500, $e);
            }

            self::successResponse();
        }
    }