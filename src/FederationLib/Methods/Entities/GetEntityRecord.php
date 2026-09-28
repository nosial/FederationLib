<?php

    namespace FederationLib\Methods\Entities;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Utilities;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Objects\ErrorResponse;

    class GetEntityRecord extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_AUTHENTICATION_REQUIRED = 'You must be authenticated to view entity records';
        private const string ERROR_IDENTIFIER_REQUIRED = 'Entity Identifier SHA-256/UUID is required';
        private const string ERROR_INVALID_IDENTIFIER = 'Given identifier is not a valid UUID, SHA-256, or entity address input';
        private const string ERROR_UNABLE_TO_RETRIEVE = 'Unable to retrieve entity';
        private const string ERROR_NOT_FOUND = 'Entity not found';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if(!Configuration::getServerConfiguration()->isEntitiesPublic() && $authenticatedOperator === null)
            {
                throw new RequestException(self::ERROR_AUTHENTICATION_REQUIRED, 401);
            }

            $entityIdentifier = Utilities::matchEntityPath(FederationServer::getPath());
            if(!$entityIdentifier)
            {
                throw new RequestException(self::ERROR_IDENTIFIER_REQUIRED, 400);
            }

            try
            {
                $entityRecord = self::resolveEntityIdentifier($entityIdentifier, self::ERROR_INVALID_IDENTIFIER);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_RETRIEVE, 500, $e);
            }

            if($entityRecord === null)
            {
                throw new RequestException(self::ERROR_NOT_FOUND, 404);
            }

            self::successResponse($entityRecord->toArray(!self::omitEntityMetadata()));
        }

        /**
         * @inheritDoc
         */
        public static function getTags(): array
        {
            return ['Entities'];
        }

        /**
         * @inheritDoc
         */
        public static function getSummary(): string
        {
            return 'Get an entity record';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Retrieves a single entity record by UUID, SHA-256 hash, or entity address. If entities are public, authentication is optional; otherwise, an operator must be authenticated.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'getEntityRecord';
        }

        /**
         * @inheritDoc
         */
        public static function getParameters(): array
        {
            return [
                [
                    'name' => 'identifier',
                    'in' => 'path',
                    'description' => 'UUID, SHA-256 hash, or entity address of the entity',
                    'required' => true,
                    'schema' => ['type' => 'string'],
                ],
            ];
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
                    'description' => 'Entity record retrieved successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => EntityRecord::getReference()],
                        ],
                    ],
                ],
                '400' => [
                    'description' => self::ERROR_INVALID_IDENTIFIER,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '401' => [
                    'description' => self::ERROR_AUTHENTICATION_REQUIRED,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '404' => [
                    'description' => self::ERROR_NOT_FOUND,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '500' => [
                    'description' => self::ERROR_UNABLE_TO_RETRIEVE,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }
