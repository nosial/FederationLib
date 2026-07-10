<?php

    namespace FederationLib\Methods\Operators;

    use FederationLib\Classes\RequestHandler;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\OperatorRecord;

    class GetSelfOperator extends RequestHandler implements RequestSpecificationInterface
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            self::successResponse(FederationServer::requireAuthenticatedOperator()->toArray());
        }

        /**
         * @inheritDoc
         */
        public static function getTags(): array
        {
            return ['Operators'];
        }

        /**
         * @inheritDoc
         */
        public static function getSummary(): string
        {
            return 'Get the authenticated operator';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Retrieves details of the currently authenticated operator.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'getSelfOperator';
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
                    'description' => 'Authenticated operator details',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => OperatorRecord::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }
