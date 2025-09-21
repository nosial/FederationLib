<?php

    namespace FederationLib\Methods;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\NamedEntityExtractor;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;

    class ScanContent extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if(!Configuration::getServerConfiguration()->isScanContentPublic() && $authenticatedOperator === null)
            {
                throw new RequestException('You must be authenticated to scan content', HttpResponseCode::UNAUTHORIZED);
            }

            $content = FederationServer::getParameter('content') ?? null;
            if($content === null)
            {
                throw new RequestException('Missing parameter \'content\'', HttpResponseCode::BAD_REQUEST);
            }

            try
            {
                $scanResults = NamedEntityExtractor::scanContent($content);
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException('There was an internal server error while scanning the content', HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            self::successResponse(array_map(fn($result) => $result->toArray(), $scanResults));
        }
    }