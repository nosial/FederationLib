<?php

    namespace FederationServer\Methods\Operators;

    use FederationServer\Classes\Logger;
    use FederationServer\Classes\Managers\OperatorManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Classes\Validate;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;

    class ManageBlacklistPermission extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!$authenticatedOperator->canManageOperators())
            {
                throw new RequestException('Insufficient permissions manage permissions', 403);
            }

            if(!preg_match('#^/operators/([a-fA-F0-9\-]{36,})/manage_blacklist$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Missing required parameters', 400);
            }

            $operatorUuid = $matches[1];
            $enabled = (bool)filter_var(FederationServer::getParameter('enabled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if(!Validate::uuid($operatorUuid))
            {
                throw new RequestException('Invalid operator UUID', 400);
            }

            try
            {
                OperatorManager::setManageBlacklist($operatorUuid, $enabled);
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException('Unable to manage operator\'s permissions', 500, $e);
            }

            self::successResponse();
        }
    }