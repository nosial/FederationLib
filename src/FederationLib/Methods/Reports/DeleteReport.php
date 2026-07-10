<?php

    namespace FederationLib\Methods\Reports;

    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\ReportManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\SuccessResponse;

    class DeleteReport extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to manage reports';
        private const string ERROR_UUID_REQUIRED = 'Report UUID is required';
        private const string ERROR_INVALID_UUID = 'Invalid report UUID';
        private const string ERROR_NOT_FOUND = 'Report not found';
        private const string ERROR_UNABLE_TO_DELETE = 'Unable to delete report';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!$authenticatedOperator->hasManagementPermissions())
            {
                throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, HttpResponseCode::FORBIDDEN);
            }

            if(!preg_match('#^/reports/([a-fA-F0-9\-]{36})$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException(self::ERROR_UUID_REQUIRED, HttpResponseCode::BAD_REQUEST);
            }

            $reportUuid = $matches[1];
            if(!$reportUuid || !Validate::uuid($reportUuid))
            {
                throw new RequestException(self::ERROR_INVALID_UUID, HttpResponseCode::BAD_REQUEST);
            }

            try
            {
                $reportRecord = ReportManager::getReport($reportUuid);
                if($reportRecord === null)
                {
                    throw new RequestException(self::ERROR_NOT_FOUND, HttpResponseCode::NOT_FOUND);
                }

                ReportManager::deleteReport($reportUuid);
                AuditLogManager::createEntry(AuditLogType::REPORT_DELETED, sprintf(
                    'Report %s deleted by operator %s',
                    $reportUuid,
                    $authenticatedOperator->getName()
                ), $authenticatedOperator->getUuid());
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_DELETE, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            self::successResponse();
        }

        /**
         * @inheritDoc
         */
        public static function getTags(): array
        {
            return ['Reports'];
        }

        /**
         * @inheritDoc
         */
        public static function getSummary(): string
        {
            return 'Delete a report';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Permanently deletes a report by UUID. Requires management permissions.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'deleteReport';
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
                    'description' => 'Report deleted successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => SuccessResponse::getReference()],
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
                    'description' => self::ERROR_UNABLE_TO_DELETE,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }
