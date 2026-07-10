<?php

    namespace FederationLib\Methods;

    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\SpecificationGenerator;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;

    class GetSpecification extends RequestHandler implements RequestSpecificationInterface
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            if(str_ends_with(FederationServer::getPath(), '.json'))
            {
                header('Content-Disposition: attachment; filename="specification.json"');
            }

            self::successResponse(SpecificationGenerator::generate());
        }

        /**
         * @inheritDoc
         */
        public static function getTags(): array
        {
            return ['Specification'];
        }

        /**
         * @inheritDoc
         */
        public static function getSummary(): string
        {
            return 'Get API specification';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Returns the OpenAPI specification for the Federation API as a JSON response. Append .json to download the specification as a file.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'getSpecification';
        }

        /**
         * @inheritDoc
         */
        public static function getParameters(): array
        {
            return [];
        }

        /**
         * @inheritDoc
         */
        public static function getRequestBody(): ?array
        {
            return null;
        }

        /**
         * @inheritDoc
         */
        public static function getResponses(): array
        {
            return [
                '200' => [
                    'description' => 'OpenAPI specification',
                    'content' => ['application/json' => ['schema' => ['type' => 'object']]],
                ],
            ];
        }
    }
