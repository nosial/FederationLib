<?php

    namespace FederationLib\Methods\Audit;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\AuditLog;
    use FederationLib\Objects\ErrorResponse;

    class ViewAuditEntry extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_AUDIT_LOGS_DISABLED = 'Public audit logs are disabled and no operator is authenticated';
        private const string ERROR_AUDIT_UUID_REQUIRED = 'Audit UUID is required';
        private const string ERROR_INVALID_AUDIT_UUID = 'Invalid audit UUID';
        private const string ERROR_AUDIT_LOG_NOT_FOUND = 'Audit log not found';
        private const string ERROR_UNABLE_TO_RETRIEVE = 'Unable to retrieve audit log';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if(!Configuration::getServerConfiguration()->isAuditLogsPublic() && $authenticatedOperator === null)
            {
                throw new RequestException(self::ERROR_AUDIT_LOGS_DISABLED, 401);
            }

            if(!preg_match('#^/audit/([a-fA-F0-9\-]{36})$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException(self::ERROR_AUDIT_UUID_REQUIRED, 400);
            }

            $entryUuid = $matches[1];
            if(!$entryUuid || !Validate::uuid($entryUuid))
            {
                throw new RequestException(self::ERROR_INVALID_AUDIT_UUID, 400);
            }

            try
            {
                $logRecord = AuditLogManager::getEntry($entryUuid);
                if(!$logRecord)
                {
                    throw new RequestException(self::ERROR_AUDIT_LOG_NOT_FOUND, 404);
                }

                if($authenticatedOperator === null)
                {
                    $publicTypes = Configuration::getServerConfiguration()->getPublicAuditEntries();
                    if(!in_array($logRecord->getType(), $publicTypes, true))
                    {
                        throw new RequestException(self::ERROR_AUDIT_LOG_NOT_FOUND, 404);
                    }
                }

                self::successResponse($logRecord->toArray());
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_RETRIEVE, 500, $e);
            }
        }

        /**
         * @inheritDoc
         */
        public static function getTags(): array
        {
            return ['Audit'];
        }

        /**
         * @inheritDoc
         */
        public static function getSummary(): string
        {
            return 'View an audit entry';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Retrieves a single audit log entry by UUID. If audit logs are public, authentication is optional; otherwise, an operator must be authenticated.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'viewAuditEntry';
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
                    'description' => self::ERROR_AUDIT_UUID_REQUIRED,
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
                    'description' => 'Audit log entry retrieved successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => AuditLog::getReference()],
                        ],
                    ],
                ],
                '400' => [
                    'description' => self::ERROR_INVALID_AUDIT_UUID,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '403' => [
                    'description' => self::ERROR_AUDIT_LOGS_DISABLED,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '404' => [
                    'description' => self::ERROR_AUDIT_LOG_NOT_FOUND,
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

