<?php

    namespace FederationServer\Methods\Operators;

    use FederationServer\Classes\Configuration;
    use FederationServer\Classes\Managers\OperatorManager;
    use FederationServer\Classes\RequestHandler;
    use FederationServer\Exceptions\DatabaseOperationException;
    use FederationServer\Exceptions\RequestException;
    use FederationServer\FederationServer;

    class ListOperators extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if(!$authenticatedOperator->canManageOperators())
            {
                throw new RequestException('Insufficient permissions to list operators', 403);
            }

            $limit = (int) (FederationServer::getParameter('limit') ?? Configuration::getServerConfiguration()->getListOperatorsMaxItems());
            $page = (int) (FederationServer::getParameter('page') ?? 1);

            if($limit < 1 || $limit > Configuration::getServerConfiguration()->getListOperatorsMaxItems())
            {
                $limit = Configuration::getServerConfiguration()->getListOperatorsMaxItems();
            }

            if($page < 1)
            {
                $page = 1;
            }

            try
            {
                $operators = OperatorManager::getOperators($limit, $page);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Unable to retrieve operators', 500, $e);
            }

            $result = array_map(fn($op) => $op->toArray(), $operators);
            self::successResponse($result);
        }
    }

