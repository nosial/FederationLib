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

            $operatorUuid = FederationServer::getParameter('uuid');
            if($operatorUuid !== null)
            {
                // Ensure the authenticated operator has permission to delete operators.
                if(!$authenticatedOperator->canManageOperators())
                {
                    throw new RequestException('Unauthorized: Insufficient permissions to refresh other operators API keys', 403);
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
                Logger::log()->error('Database error while refreshing operator\'s API Key: ' . $e->getMessage(), $e);
                throw new RequestException('Internal Server Error: Unable to refresh operator\'s API Key', 500, $e);
            }

            // Respond with the UUID of the newly created operator.
            self::successResponse($newApiKey);
        }
    }