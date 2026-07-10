<?php

    namespace FederationLib\Methods\Reports;

    use Exception;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Classes\Managers\ReportManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\SuccessResponse;

    class AssignOperator extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to manage reports';
        private const string ERROR_OPERATOR_NOT_FOUND = 'Operator not found';
        private const string ERROR_OPERATOR_DISABLED = 'Operator is disabled';
        private const string ERROR_INSUFFICIENT_ASSIGNEE_PERMISSIONS = 'Insufficient permissions to manage reports';
        private const string ERROR_REPORT_NOT_FOUND = 'Report not found';
        private const string ERROR_FAILED_TO_GET_OPERATOR = 'Failed to get operator';
        private const string ERROR_FAILED_TO_GET_REPORT = 'Failed to get report';
        private const string ERROR_FAILED_TO_ASSIGN = 'Failed to assign operator to the report';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            // Get the parameters
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();

            if(!preg_match('#^/reports/([a-fA-F0-9\-]{36})/assign$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException(self::ERROR_REPORT_NOT_FOUND, HttpResponseCode::BAD_REQUEST);
            }

            $reportUuid = $matches[1];
            $assignedOperator = FederationServer::getParameter('operator');

            // If the authenticated operator cannot manage the blacklist, deny access
            if(!$authenticatedOperator->hasManagementPermissions())
            {
                throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, HttpResponseCode::FORBIDDEN);
            }

            // If the assigned operator is empty, assume the authenticated operator is the assigned operator
            if(empty($assignedOperator))
            {
                $assignedOperator = $authenticatedOperator->getUuid();
            }

            // Resolve the operator record
            if($assignedOperator === $authenticatedOperator->getUuid())
            {
                $assignedOperator = $authenticatedOperator;
            }
            else
            {
                try
                {
                    $assignedOperator = OperatorManager::getOperator($assignedOperator);
                }
                catch (Exception $e)
                {
                    throw new RequestException(self::ERROR_FAILED_TO_GET_OPERATOR, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
                }
            }

            // If the assigned operator is not found, deny access
            if($assignedOperator === null)
            {
                throw new RequestException(self::ERROR_OPERATOR_NOT_FOUND, HttpResponseCode::NOT_FOUND);
            }

            // If the assigned operator is disabled, deny access
            if($assignedOperator->isDisabled())
            {
                throw new RequestException(self::ERROR_OPERATOR_DISABLED, HttpResponseCode::BAD_REQUEST);
            }

            // If the assigned operator cannot manage the blacklist, deny access
            if(!$assignedOperator->hasManagementPermissions())
            {
                throw new RequestException(self::ERROR_INSUFFICIENT_ASSIGNEE_PERMISSIONS, HttpResponseCode::FORBIDDEN);
            }

            // Get the report
            try
            {
                if(!ReportManager::reportExists($reportUuid))
                {
                    throw new RequestException(self::ERROR_REPORT_NOT_FOUND, HttpResponseCode::NOT_FOUND);
                }
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_FAILED_TO_GET_REPORT, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            try
            {
                // Assign the operator and create the audit log
                ReportManager::assignOperator($reportUuid, $assignedOperator->getUuid());

                // Create the audit log entry depending on who's the assigned operator
                if($authenticatedOperator->getUuid() === $assignedOperator->getUuid())
                {
                    AuditLogManager::createEntry(AuditLogType::REPORT_OPERATOR_ASSIGNED,
                        message: sprintf('Report %s assigned to operator %s', $reportUuid, $assignedOperator->getName()),
                        operatorUuid: $assignedOperator->getUuid()
                    );
                }
                else
                {
                    AuditLogManager::createEntry(AuditLogType::REPORT_OPERATOR_ASSIGNED,
                        message: sprintf('Report %s assigned to operator %s by %s', $reportUuid, $assignedOperator->getName(), $authenticatedOperator->getName()),
                        operatorUuid: $assignedOperator->getUuid()
                    );
                }
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_FAILED_TO_ASSIGN, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
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
            return 'Assign an operator to a report';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Assigns an operator to manage a report. If no operator is specified, the authenticated operator is assigned. Requires management permissions.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'assignOperatorToReport';
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
                    'description' => 'UUID of the report to assign an operator to',
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
                                'operator' => [
                                    'type' => 'string',
                                    'format' => 'uuid',
                                    'description' => 'UUID of the operator to assign (optional, defaults to authenticated operator)',
                                    'nullable' => true,
                                ],
                            ],
                            'required' => [],
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
                    'description' => 'Operator assigned to report successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => SuccessResponse::getReference()],
                        ],
                    ],
                ],
                '400' => [
                    'description' => self::ERROR_OPERATOR_DISABLED,
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
                    'description' => self::ERROR_OPERATOR_NOT_FOUND,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '500' => [
                    'description' => self::ERROR_FAILED_TO_ASSIGN,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }
