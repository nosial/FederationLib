<?php

    namespace FederationServer\Methods\Operators;

    use FederationServer\Classes\Enums\AuditLogType;
    use FederationServer\Classes\Logger;
    use FederationServer\Classes\Managers\AuditLogManager;
    use FederationServer\Classes\Managers\OperatorManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;

    class EnableOperator extends RequestHandler
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
                throw new RequestException('Unauthorized: Insufficient permissions to enable/disable operators', 403);
            }

            if(!FederationServer::getParameter('uuid'))
            {
                throw new RequestException('Bad Request: Operator UUID is required', 400);
            }

            if(!FederationServer::getParameter('enabled'))
            {
                throw new RequestException('Bad Request: Enabled status is required', 400);
            }

            $enabled = filter_var(FederationServer::getParameter('enabled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if($enabled === null)
            {
                throw new RequestException('Bad Request: Invalid enabled status', 400);
            }

            try
            {
                $existingOperator = OperatorManager::getOperator(FederationServer::getParameter('uuid'));
                if($existingOperator === null)
                {
                    throw new RequestException('Operator Not Found', 404);
                }

                if($enabled)
                {
                    OperatorManager::enableOperator(FederationServer::getParameter('uuid'));
                    AuditLogManager::createEntry(AuditLogType::OPERATOR_ENABLED, sprintf('Operator %s (%s) enabled by %s (%s)',
                        $existingOperator->getName(),
                        $existingOperator->getUuid(),
                        $authenticatedOperator->getName(),
                        $authenticatedOperator->getUuid()
                    ), $authenticatedOperator->getUuid());
                }
                else
                {
                    OperatorManager::disableOperator(FederationServer::getParameter('uuid'));
                    AuditLogManager::createEntry(AuditLogType::OPERATOR_DISABLED, sprintf('Operator %s (%s) disabled by %s (%s)',
                        $existingOperator->getName(),
                        $existingOperator->getUuid(),
                        $authenticatedOperator->getName(),
                        $authenticatedOperator->getUuid()
                    ), $authenticatedOperator->getUuid());
                }
            }
            catch(DatabaseOperationException $e)
            {
                Logger::log()->error(sprintf('Database error while %s the operator: %s',
                    $enabled ? 'enabling' : 'disabling',
                    $e->getMessage()), $e
                );
                throw new RequestException('Internal Server Error: Unable to ' . ($enabled ? 'enable' : 'disable') . ' operator', 500, $e);
            }

            // Respond with the UUID of the newly created operator.
            self::successResponse();
        }
    }