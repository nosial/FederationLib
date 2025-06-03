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
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if(!$authenticatedOperator->canManageOperators())
            {
                throw new RequestException('Unauthorized: Insufficient permissions manage permissions', 403);
            }

            $operatorUuid = FederationServer::getParameter('uuid');
            $enabled = (bool)filter_var(FederationServer::getParameter('enabled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if($operatorUuid === null)
            {
                throw new RequestException('Bad Request: Missing required parameters', 400);
            }

            if(!Validate::uuid($operatorUuid))
            {
                throw new RequestException('Bad Request: Invalid operator UUID', 400);
            }

            try
            {
                OperatorManager::setManageBlacklist($operatorUuid, $enabled);
            }
            catch(DatabaseOperationException $e)
            {
                Logger::log()->error('Database error while managing operator\'s permissions: ' . $e->getMessage(), $e);
                throw new RequestException('Internal Server Error: Unable to manage operator\'s permissions', 500, $e);
            }

            self::successResponse();
        }
    }