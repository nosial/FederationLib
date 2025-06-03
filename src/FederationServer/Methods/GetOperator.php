<?php

    namespace FederationServer\Methods;

    use FederationServer\Classes\Enums\AuditLogType;
    use FederationServer\Classes\Logger;
    use FederationServer\Classes\Managers\AuditLogManager;
    use FederationServer\Classes\Managers\OperatorManager;
    use FederationServer\Classes\RequestHandler;
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

            if(!FederationServer::getParameter('uuid'))
            {
                throw new RequestException('Bad Request: Operator UUID is required', 400);
            }

            try
            {
                $existingOperator = OperatorManager::getOperator(FederationServer::getParameter('uuid'));
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