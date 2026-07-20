<?php

    namespace FederationLib\Methods\Operators;

    use Exception;
    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Classes\Managers\ReportManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\Categories\ReportCategory;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\ReportRecord;

    class ListOperatorReports extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_AUTHENTICATION_REQUIRED = 'Public reports are disabled and no operator is authenticated';
        private const string ERROR_UUID_REQUIRED = 'Operator UUID is required';
        private const string ERROR_INVALID_UUID = 'Invalid operator UUID';
        private const string ERROR_NOT_FOUND = 'Operator not found';
        private const string ERROR_FAILED_TO_GET_OPERATOR = 'Failed to get operator';
        private const string ERROR_UNABLE_TO_RETRIEVE = 'Unable to retrieve reports';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if(!Configuration::getServerConfiguration()->isReportsPublic() && $authenticatedOperator === null)
            {
                throw new RequestException(self::ERROR_AUTHENTICATION_REQUIRED, HttpResponseCode::UNAUTHORIZED);
            }

            $limit = (int) (FederationServer::getParameter('limit') ?? Configuration::getServerConfiguration()->getListReportsMaxItems());
            $page = (int) (FederationServer::getParameter('page') ?? 1);

            if($limit < 1 || $limit > Configuration::getServerConfiguration()->getListReportsMaxItems())
            {
                $limit = Configuration::getServerConfiguration()->getListReportsMaxItems();
            }

            if($page < 1)
            {
                $page = 1;
            }

            $categoryInput = FederationServer::getParameter('category');
            $category = $categoryInput !== null ? ReportCategory::tryFromCaseInsensitive($categoryInput) : null;

            if(!preg_match('#^/operators/([a-fA-F0-9\-]{36})/reports$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException(self::ERROR_UUID_REQUIRED, HttpResponseCode::BAD_REQUEST);
            }

            $operator = $matches[1];
            if(!$operator || !Validate::uuid($operator))
            {
                throw new RequestException(self::ERROR_INVALID_UUID, HttpResponseCode::BAD_REQUEST);
            }

            try
            {
                if (!OperatorManager::operatorExists($operator))
                {
                    throw new RequestException(self::ERROR_NOT_FOUND, HttpResponseCode::NOT_FOUND);
                }
            }
            catch (RequestException $e)
            {
                throw $e;
            }
            catch (Exception $e)
            {
                throw new RequestException(self::ERROR_FAILED_TO_GET_OPERATOR, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            try
            {
                self::successResponse(array_map(fn($report) => $report->toArray(),
                    ReportManager::getReportsBySubmittingOperator($operator, $limit, $page, $category))
                );
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_RETRIEVE, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }
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
            return 'List reports submitted by an operator';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Retrieves a paginated list of reports submitted by a specific operator. Reports must be public or the operator must be authenticated.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'listOperatorReports';
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
                    'description' => 'UUID of the operator',
                    'required' => true,
                    'schema' => ['type' => 'string', 'format' => 'uuid'],
                ],
                [
                    'name' => 'limit',
                    'in' => 'query',
                    'description' => 'Maximum number of reports to return',
                    'required' => false,
                    'schema' => ['type' => 'integer', 'minimum' => 1],
                ],
                [
                    'name' => 'page',
                    'in' => 'query',
                    'description' => 'Page number for pagination',
                    'required' => false,
                    'schema' => ['type' => 'integer', 'minimum' => 1],
                ],
                [
                    'name' => 'category',
                    'in' => 'query',
                    'description' => 'Filter by report category: OPENED, CLOSED, AUTOMATED, UNASSIGNED, ASSIGNED',
                    'required' => false,
                    'schema' => ['type' => 'string', 'enum' => ['OPENED', 'CLOSED', 'AUTOMATED', 'UNASSIGNED', 'ASSIGNED']],
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
                    'description' => 'List of reports submitted by the operator',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'array',
                                'items' => ['$ref' => ReportRecord::getReference()],
                            ],
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
