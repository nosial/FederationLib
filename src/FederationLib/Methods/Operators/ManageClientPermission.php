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

    class ManageClientPermission extends RequestHandler
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

            if(!preg_match('#^/operators/([a-fA-F0-9\-]{36})/manage_client$#', FederationServer::getPath(), $matches))
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

                OperatorManager::setClient($operatorUuid, $enabled);
                AuditLogManager::createEntry(AuditLogType::OPERATOR_PERMISSIONS_CHANGED, sprintf(
                    'Operator %s (%s) %s client permissions by %s (%s)',
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