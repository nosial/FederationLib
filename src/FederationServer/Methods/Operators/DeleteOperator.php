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

    class DeleteOperator extends RequestHandler
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
                throw new RequestException('Insufficient permissions to delete operators', 403);
            }

            if(!preg_match('#^/operators/([a-fA-F0-9\-]{36,})/delete$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Operator UUID required', 400);
            }

            $operatorUuid = $matches[1];
            if(!$operatorUuid || !Validate::uuid($operatorUuid))
            {
                throw new RequestException('a valid Operator UUID required', 400);
            }

            try
            {
                $existingOperator = OperatorManager::getOperator($operatorUuid);
                if($existingOperator === null)
                {
                    throw new RequestException('Operator Not Found', 404);
                }

                OperatorManager::deleteOperator($operatorUuid);
                AuditLogManager::createEntry(AuditLogType::OPERATOR_DELETED, sprintf('Operator %s (%s) deleted by %s (%s)',
                    $existingOperator->getName(),
                    $existingOperator->getUuid(),
                    $authenticatedOperator->getName(),
                    $authenticatedOperator->getUuid()
                ), $authenticatedOperator->getUuid());
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException('Unable to create operator', 500, $e);
            }

            // Respond with the UUID of the newly created operator.
            self::successResponse();
        }
    }