<?php

    namespace FederationServer\Methods;

    use FederationServer\Classes\Logger;
    use FederationServer\Classes\Managers\OperatorManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Classes\Validate;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;

    class GetOperator extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();

            // Ensure the authenticated operator has permission to delete operators.
            if(!$authenticatedOperator->canManageOperators())
            {
                throw new RequestException('Unauthorized: Insufficient permissions to get operators', 403);
            }

            if(!preg_match('#^/operators/([a-fA-F0-9\-]{36,})$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Operator UUID required', 405);
            }

            $operatorUuid = $matches[1];
            if(!$operatorUuid || !Validate::uuid($operatorUuid))
            {
                throw new RequestException('Invalid operator UUID', 400);
            }

            try
            {
                $existingOperator = OperatorManager::getOperator($operatorUuid);
                if($existingOperator === null)
                {
                    throw new RequestException('Operator Not Found', 404);
                }
            }
            catch(DatabaseOperationException $e)
            {
                Logger::log()->error('Database error while getting operator: ' . $e->getMessage(), $e);
                throw new RequestException('Internal Server Error: Unable to get operator', 500, $e);
            }

            // Respond with the UUID of the newly created operator.
            self::successResponse($existingOperator->toArray());
        }
    }