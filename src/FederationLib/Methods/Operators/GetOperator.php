<?php

    namespace FederationLib\Methods\Operators;

    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;

    class GetOperator extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if(!preg_match('#^/operators/([a-fA-F0-9\-]{36,})$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException('Operator UUID required', 405);
            }

            $operatorUuid = $matches[1];
            if(!$operatorUuid || !Validate::uuid($operatorUuid))
            {
                throw new RequestException('Invalid operator UUID', 400);
            }

            try
            {
                $existingOperator = OperatorManager::getOperator($operatorUuid);
                if($existingOperator === null)
                {
                    throw new RequestException('Operator Not Found', 404);
                }
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException('Unable to get operator', 500, $e);
            }

            if(!$authenticatedOperator?->canManageOperators())
            {
                // Clear API key if the authenticated operator does not have permission to manage operators
                $existingOperator->clearApiKey();
            }

            // Respond with public record if the authenticated operator cannot manage operators
            self::successResponse($existingOperator->toArray());
        }
    }