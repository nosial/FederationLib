<?php

    namespace FederationServer\Methods\Operators;

    use FederationServer\Classes\Managers\AuditLogManager;
    use FederationServer\Classes\Managers\OperatorManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Classes\Validate;
    use FederationServer\Enums\AuditLogType;
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
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();

            // Ensure the authenticated operator has permission to delete operators.
            if(!$authenticatedOperator->canManageOperators())
            {
                throw new RequestException('Insufficient permissions to enable/disable operators', 403);
            }

            if(!preg_match('#^/operators/([a-fA-F0-9\-]{36,})/enable$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Operator UUID is required', 400);
            }

            $operatorUuid = $matches[1];
            if(!$operatorUuid || !Validate::uuid($operatorUuid))
            {
                throw new RequestException('a valid operator UUID is required', 400);
            }

            try
            {
                $existingOperator = OperatorManager::getOperator($operatorUuid);
                if($existingOperator === null)
                {
                    throw new RequestException('Operator Not Found', 404);
                }

                if(!$existingOperator->isDisabled())
                {
                    throw new RequestException('Operator is already enabled', 400);
                }

                OperatorManager::enableOperator($operatorUuid);
                AuditLogManager::createEntry(AuditLogType::OPERATOR_ENABLED, sprintf('Operator %s (%s) enabled by %s (%s)',
                    $existingOperator->getName(),
                    $existingOperator->getUuid(),
                    $authenticatedOperator->getName(),
                    $authenticatedOperator->getUuid()
                ), $authenticatedOperator->getUuid());
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException('Unable to enable operator', 500, $e);
            }

            // Respond with the UUID of the newly created operator.
            self::successResponse();
        }
    }