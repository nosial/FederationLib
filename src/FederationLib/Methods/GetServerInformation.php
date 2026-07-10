<?php

    namespace FederationLib\Methods;

    use FederationLib\Classes\RequestHandler;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\ServerInformation;

    class GetServerInformation extends RequestHandler implements RequestSpecificationInterface
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            self::successResponse(FederationServer::getServerInformation());
        }

        /**
         * @inheritDoc
         */
        public static function getTags(): array
        {
            return ['Server'];
        }

        /**
         * @inheritDoc
         */
        public static function getSummary(): string
        {
            return 'Get server information';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Retrieves information about this Federation server, including version and configuration details.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'getServerInformation';
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
                    'description' => 'Server information',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ServerInformation::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }
