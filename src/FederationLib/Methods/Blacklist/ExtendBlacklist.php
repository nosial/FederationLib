<?php

    namespace FederationLib\Methods\Blacklist;

    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\BlacklistManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\SuccessResponse;

    class ExtendBlacklist extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to manage the blacklist';
        private const string ERROR_UUID_REQUIRED = 'Blacklist UUID is required';
        private const string ERROR_INVALID_UUID = 'Invalid blacklist UUID';
        private const string ERROR_NOT_FOUND = 'Blacklist record not found';
        private const string ERROR_IS_PERMANENT = 'Cannot extend a permanent blacklist record';
        private const string ERROR_NOT_ACTIVE = 'Blacklist record is no longer active';
        private const string ERROR_SECONDS_REQUIRED = 'Seconds to extend is required';
        private const string ERROR_INVALID_SECONDS = 'Seconds must be a positive integer';
        private const string ERROR_UNABLE_TO_EXTEND = 'Unable to extend blacklist record';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!$authenticatedOperator->hasManagementPermissions())
            {
                throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, 403);
            }

            if(!preg_match('#^/blacklist/([a-fA-F0-9\-]{36})/extend$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException(self::ERROR_UUID_REQUIRED, 400);
            }

            $blacklistUuid = $matches[1];
            if(!$blacklistUuid || !Validate::uuid($blacklistUuid))
            {
                throw new RequestException(self::ERROR_INVALID_UUID, 400);
            }

            $seconds = FederationServer::getParameter('seconds');
            if($seconds === null)
            {
                throw new RequestException(self::ERROR_SECONDS_REQUIRED, 400);
            }

            $seconds = (int)$seconds;
            if($seconds <= 0)
            {
                throw new RequestException(self::ERROR_INVALID_SECONDS, 400);
            }

            try
            {
                $blacklistRecord = BlacklistManager::getBlacklistEntry($blacklistUuid);

                if($blacklistRecord === null)
                {
                    throw new RequestException(self::ERROR_NOT_FOUND, 404);
                }

                if($blacklistRecord->getExpires() === null)
                {
                    throw new RequestException(self::ERROR_IS_PERMANENT, 400);
                }

                if($blacklistRecord->isLifted())
                {
                    throw new RequestException(self::ERROR_NOT_ACTIVE, 400);
                }

                BlacklistManager::extendBlacklistRecord($blacklistUuid, $seconds);

                AuditLogManager::createEntry(AuditLogType::BLACKLIST_EXTENDED, sprintf(
                    'Blacklist record %s extended by %d seconds by operator %s',
                    $blacklistUuid,
                    $seconds,
                    $authenticatedOperator->getName()
                ), $authenticatedOperator->getUuid(), $blacklistRecord->getEntityUuid(), $blacklistUuid);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_EXTEND, 500, $e);
            }

            self::successResponse();
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
            return 'Extend a blacklist record';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Extends the expiration time of an active, non-permanent blacklist record by the specified number of seconds. Requires management permissions.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'extendBlacklist';
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
            return [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'seconds' => [
                                    'type' => 'integer',
                                    'description' => 'Number of seconds to extend the blacklist expiration',
                                    'minimum' => 1,
                                ],
                            ],
                            'required' => ['seconds'],
                        ],
                    ],
                ],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getResponses(): array
        {
            return [
                '200' => [
                    'description' => 'Blacklist record extended successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => SuccessResponse::getReference()],
                        ],
                    ],
                ],
                '400' => [
                    'description' => self::ERROR_INVALID_SECONDS,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '403' => [
                    'description' => self::ERROR_INSUFFICIENT_PERMISSIONS,
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
                    'description' => self::ERROR_UNABLE_TO_EXTEND,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }
