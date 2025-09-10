<?php

    namespace FederationLib\Methods\Evidence;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;

    class GetEvidenceRecord extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if(!Configuration::getServerConfiguration()->isEvidencePublic() && $authenticatedOperator === null)
            {
                throw new RequestException('You must be authenticated to access evidence', 401);
            }

            if(!preg_match('#^/evidence/([a-fA-F0-9\-]{36})$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Evidence UUID required', 405);
            }

            $evidenceUuid = $matches[1];
            if(!$evidenceUuid || !Validate::uuid($evidenceUuid))
            {
                throw new RequestException('Invalid evidence UUID', 400);
            }

            try
            {
                $evidenceRecord = EvidenceManager::getEvidence($evidenceUuid);
                if($evidenceRecord === null)
                {
                    throw new RequestException('Evidence Not Found', 404);
                }

                if($evidenceRecord->isConfidential() && ($authenticatedOperator === null || !$authenticatedOperator->canManageBlacklist()))
                {
                    throw new RequestException('Confidential evidence access is restricted', 403);
                }
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException('Unable to get evidence', 500, $e);
            }

            self::successResponse($evidenceRecord->toArray());
        }
    }

