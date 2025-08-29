<?php

    namespace FederationLib\Methods\Operators;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Logger;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;

    class ListOperators extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!$authenticatedOperator->canManageOperators())
            {
                throw new RequestException('Insufficient permissions to list operators', 403);
            }

            $limit = (int) (FederationServer::getParameter('limit') ?? Configuration::getServerConfiguration()->getListOperatorsMaxItems());
            $page = (int) (FederationServer::getParameter('page') ?? 1);
            Logger::log()->debug("ListOperators: limit=$limit, page=$page");

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
                self::successResponse(array_map(fn($op) => $op->toArray(), $operators));
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Unable to retrieve operators', 500, $e);
            }
        }
    }

