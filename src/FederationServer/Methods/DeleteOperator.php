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

    class DeleteOperator extends RequestHandler
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
                throw new RequestException('Unauthorized: Insufficient permissions to delete operators', 403);
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

                OperatorManager::deleteOperator(FederationServer::getParameter('uuid'));
                AuditLogManager::createEntry(AuditLogType::OPERATOR_DELETED, sprintf('Operator %s (%s) deleted by %s (%s)',
                    $existingOperator->getName(),
                    $existingOperator->getUuid(),
                    $authenticatedOperator->getName(),
                    $authenticatedOperator->getUuid()
                ), $authenticatedOperator->getUuid());
            }
            catch(DatabaseOperationException $e)
            {
                Logger::log()->error('Database error while creating operator: ' . $e->getMessage(), $e);
                throw new RequestException('Internal Server Error: Unable to create operator', 500, $e);
            }

            // Respond with the UUID of the newly created operator.
            self::successResponse();
        }
    }