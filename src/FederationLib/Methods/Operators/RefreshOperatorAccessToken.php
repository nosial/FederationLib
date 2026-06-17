<?php

    namespace FederationLib\Methods\Operators;

    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;

    class RefreshOperatorAccessToken extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(preg_match('#^/operators/([a-fA-F0-9\-]{36})/refresh$#', FederationServer::getPath(), $matches))
            {
                $operatorUuid = $matches[1];
                // Ensure the authenticated operator has permission to refresh other operators' Access Tokens.
                if($operatorUuid !== $authenticatedOperator->getUuid() && !$authenticatedOperator->canManageOperators())
                {
                    throw new RequestException('Insufficient permissions to refresh other operators Access Tokens', 403);
                }
            }
            else
            {
                $operatorUuid = $authenticatedOperator->getUuid();
            }

            try
            {
                if($operatorUuid !== $authenticatedOperator->getUuid())
                {
                    $existingOperator = OperatorManager::getOperator($operatorUuid);
                    if($existingOperator === null)
                    {
                        throw new RequestException('Operator Not Found', 404);
                    }
                }
                else
                {
                    $existingOperator = $authenticatedOperator;
                }

                if(OperatorManager::isRootOperator($operatorUuid))
                {
                    throw new RequestException('Cannot refresh Access Token for root operator', 403);
                }

                $newAccessToken = OperatorManager::newAccessToken($operatorUuid);
                AuditLogManager::createEntry(AuditLogType::OPERATOR_PERMISSIONS_CHANGED, sprintf(
                    'Operator %s (%s) refreshed Access Token by %s',
                    $existingOperator->getName(),
                    $existingOperator->getUuid(),
                    $authenticatedOperator->getName()
                ), $authenticatedOperator->getUuid());
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException('Unable to refresh operator\'s Access token', 500, $e);
            }

            self::successResponse($newAccessToken);
        }
    }