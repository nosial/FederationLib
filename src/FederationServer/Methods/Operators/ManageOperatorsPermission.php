<?php

    namespace FederationServer\Methods\Operators;

    use FederationServer\Classes\Enums\AuditLogType;
    use FederationServer\Classes\Managers\AuditLogManager;
    use FederationServer\Classes\Managers\OperatorManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Classes\Validate;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;

    class ManageOperatorsPermission extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!$authenticatedOperator->canManageOperators())
            {
                throw new RequestException('Insufficient permissions manage permissions', 403);
            }

            if(!preg_match('#^/operators/([a-fA-F0-9\-]{36,})/manage_operators$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Missing required parameters', 400);
            }

            $operatorUuid = $matches[1];
            $enabled = (bool)filter_var(FederationServer::getParameter('enabled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if(!Validate::uuid($operatorUuid))
            {
                throw new RequestException('Invalid operator UUID', 400);
            }

            try
            {
                $targetOperator = OperatorManager::getOperator($operatorUuid);
                if($targetOperator === null)
                {
                    throw new RequestException('Operator Not Found', 404);
                }

                OperatorManager::setManageOperators($operatorUuid, $enabled);
                AuditLogManager::createEntry(AuditLogType::OPERATOR_PERMISSIONS_CHANGED, sprintf(
                    'Operator %s (%s) %s operator management permissions by %s (%s)',
                    $targetOperator->getName(),
                    $targetOperator->getUuid(),
                    $enabled ? 'enabled' : 'disabled',
                    $authenticatedOperator->getName(),
                    $authenticatedOperator->getUuid()
                ), $authenticatedOperator->getUuid());
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException('Unable to manage operator\'s permissions', 500, $e);
            }

            self::successResponse();
        }
    }