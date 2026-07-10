<?php

    namespace FederationLib\Methods\Blacklist;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\BlacklistRecord;
    use FederationLib\Objects\ErrorResponse;

    class GetBlacklistRecord extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_AUTHENTICATION_REQUIRED = 'You must be authenticated to view blacklist records';
        private const string ERROR_UUID_REQUIRED = 'Blacklist UUID is required';
        private const string ERROR_INVALID_UUID = 'Invalid blacklist UUID';
        private const string ERROR_NOT_FOUND = 'Blacklist record not found';
        private const string ERROR_UNABLE_TO_RETRIEVE = 'Unable to retrieve blacklist records';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if(!Configuration::getServerConfiguration()->isBlacklistPublic() && $authenticatedOperator === null)
            {
                throw new RequestException(self::ERROR_AUTHENTICATION_REQUIRED, 401);
            }

            if(!preg_match('#^/blacklist/([a-fA-F0-9\-]{36})$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException(self::ERROR_UUID_REQUIRED, 400);
            }

            $blacklistUuid = $matches[1];
            if(!$blacklistUuid || !Validate::uuid($blacklistUuid))
            {
                throw new RequestException(self::ERROR_INVALID_UUID, 400);
            }

            try
            {
                if(!BlacklistManager::blacklistExists($blacklistUuid))
                {
                    throw new RequestException(self::ERROR_NOT_FOUND, 404);
                }

                $blacklistRecord = BlacklistManager::getBlacklistEntry($blacklistUuid);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_RETRIEVE, 500, $e);
            }

            self::successResponse($blacklistRecord->toArray());
        }

        /**
         * @inheritDoc
         */
        public static function getTags(): array
        {
            return ['Blacklist'];
        }

        /**
         * @inheritDoc
         */
        public static function getSummary(): string
        {
            return 'Get a blacklist record';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Retrieves a single blacklist record by UUID. If the blacklist is public, authentication is optional; otherwise, an operator must be authenticated.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'getBlacklistRecord';
        }

        /**
         * @inheritDoc
         */
        public static function getParameters(): array
        {
            return [
                [
                    'name' => 'uuid',
                    'in' => 'path',
                    'description' => self::ERROR_UUID_REQUIRED,
                    'required' => true,
                    'schema' => ['type' => 'string', 'format' => 'uuid'],
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
                    'description' => 'Blacklist record retrieved successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => BlacklistRecord::getReference()],
                        ],
                    ],
                ],
                '400' => [
                    'description' => self::ERROR_INVALID_UUID,
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
