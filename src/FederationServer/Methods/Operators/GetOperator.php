<?php

    namespace FederationServer\Methods\Operators;

    use FederationServer\Classes\Logger;
    use FederationServer\Classes\Managers\OperatorManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Classes\Validate;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;

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

            if($authenticatedOperator?->canManageOperators())
            {
                // If the authenticated operator can manage operators, return the full record
                self::successResponse($existingOperator->toArray());
                return;
            }

            // Respond with public record if the authenticated operator cannot manage operators
            self::successResponse($existingOperator->toPublicRecord()->toArray());
        }
    }