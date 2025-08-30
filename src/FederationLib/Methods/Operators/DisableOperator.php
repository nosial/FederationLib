<?php

    namespace FederationLib\Methods\Operators;

    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;

    class DisableOperator extends RequestHandler
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

            if(!preg_match('#^/operators/([a-fA-F0-9\-]{36})/disable$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Operator UUID is required', 400);
            }

            $operatorUuid = $matches[1];
            if(!$operatorUuid || !Validate::uuid($operatorUuid))
            {
                throw new RequestException('a valid Operator UUID is required', 400);
            }

            try
            {
                $existingOperator = OperatorManager::getOperator($operatorUuid);
                if($existingOperator === null)
                {
                    throw new RequestException('Operator Not Found', 404);
                }

                if($existingOperator->isDisabled())
                {
                    throw new RequestException('Operator is already enabled', 400);
                }

                OperatorManager::disableOperator($operatorUuid);
                AuditLogManager::createEntry(AuditLogType::OPERATOR_DISABLED, sprintf('Operator %s (%s) disabled by %s (%s)',
                    $existingOperator->getName(),
                    $existingOperator->getUuid(),
                    $authenticatedOperator->getName(),
                    $authenticatedOperator->getUuid()
                ), $authenticatedOperator->getUuid());
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException('Unable to disable operator', 500, $e);
            }

            self::successResponse();
        }
    }