<?php

    namespace FederationServer\Methods\Operators;

    use FederationServer\Classes\Logger;
    use FederationServer\Classes\Managers\OperatorManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;

    class RefreshOperatorApiKey extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();

            $operatorUuid = null;
            if(preg_match('#^/operators/([a-fA-F0-9\-]{36,})/refresh$#', FederationServer::getPath(), $matches))
            {
                $operatorUuid = $matches[1];
                // Ensure the authenticated operator has permission to refresh other operators' API keys.
                if($operatorUuid !== $authenticatedOperator->getUuid() && !$authenticatedOperator->canManageOperators())
                {
                    throw new RequestException('Insufficient permissions to refresh other operators API keys', 403);
                }
            }
            else
            {
                $operatorUuid = $authenticatedOperator->getUuid();
            }

            try
            {
                $newApiKey = OperatorManager::refreshApiKey($operatorUuid);
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException('Unable to refresh operator\'s API Key', 500, $e);
            }

            // Respond with the UUID of the newly created operator.
            self::successResponse($newApiKey);
        }
    }