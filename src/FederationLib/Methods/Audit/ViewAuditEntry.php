<?php

    namespace FederationLib\Methods\Audit;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;

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
                throw new RequestException('Public audit logs are disabled and no operator is authenticated', 403);
            }

            if(!preg_match('#^/audit/([a-fA-F0-9\-]{36,})$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Audit UUID is required', 400);
            }

            $entryUuid = $matches[1];
            if(!$entryUuid || !Validate::uuid($entryUuid))
            {
                throw new RequestException('Invalid Audit UUID', 400);
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
                throw new RequestException('Unable to retrieve audit log', 500, $e);
            }
        }
    }

