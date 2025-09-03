<?php

    namespace FederationLib\Methods\Operators;

    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use InvalidArgumentException;

    class CreateOperator extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();

            // Ensure the authenticated operator has permission to create new operators.
            if(!$authenticatedOperator->canManageOperators())
            {
                throw new RequestException('Insufficient permissions to create operators', 403);
            }

            if(!FederationServer::getParameter('name'))
            {
                throw new RequestException('Operator name is required', 400);
            }

            try
            {
                $operatorUuid = OperatorManager::createOperator(FederationServer::getParameter('name'));
                AuditLogManager::createEntry(AuditLogType::OPERATOR_CREATED, sprintf('Operator %s (%s) created by %s (%s)',
                    FederationServer::getParameter('name'),
                    $operatorUuid,
                    $authenticatedOperator->getName(),
                    $authenticatedOperator->getUuid()
                ), $authenticatedOperator->getUuid());
            }
            catch(InvalidArgumentException $e)
            {
                throw new RequestException($e->getMessage(), 400, $e);
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException('Unable to create operator', 500, $e);
            }

            // Respond with the UUID of the newly created operator.
            self::successResponse($operatorUuid, 201);
        }
    }