<?php

    namespace FederationServer\Methods\Audit;

    use FederationServer\Classes\Configuration;
    use FederationServer\Classes\Managers\AuditLogManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Classes\Validate;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;

    class ViewAuditEntry extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if(!Configuration::getServerConfiguration()->isAuditLogsPublic() && $authenticatedOperator === null)
            {
                throw new RequestException('Unauthorized: Public audit logs are disabled and no operator is authenticated', 403);
            }

            if(!preg_match('#^/audit/([a-fA-F0-9\-]{36,})$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Bad Request: Audit UUID is required', 400);
            }

            $entryUuid = $matches[1];
            if(!$entryUuid || !Validate::uuid($entryUuid))
            {
                throw new RequestException('Bad Request: Invalid Audit UUID', 400);
            }

            try
            {
                $logRecord = AuditLogManager::getEntry($entryUuid);
                if(!$logRecord)
                {
                    throw new RequestException('Audit log not found', 404);
                }

                self::successResponse($logRecord->toArray());
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Internal Server Error: Unable to retrieve audit log', 500, $e);
            }
        }
    }

