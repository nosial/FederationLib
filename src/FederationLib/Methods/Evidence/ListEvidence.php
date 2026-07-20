<?php

    namespace FederationLib\Methods\Evidence;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Enums\Categories\EvidenceCategory;
    use FederationLib\Enums\OrderType;
    use FederationLib\Enums\OrderTypes\EvidenceOrderType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\EvidenceRecord;

    class ListEvidence extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_AUTH_REQUIRED = 'You must be authenticated to list evidence';
        private const string ERROR_CONFIDENTIAL_AUTH_REQUIRED = 'You must be authenticated to include confidential evidence';
        private const string ERROR_CONFIDENTIAL_PERMISSION = 'You do not have permission to include confidential evidence';
        private const string ERROR_UNABLE_TO_RETRIEVE = 'Unable to retrieve evidence';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if(!Configuration::getServerConfiguration()->isEvidencePublic() && $authenticatedOperator === null)
            {
                throw new RequestException(self::ERROR_AUTH_REQUIRED, 401);
            }

            $limit = (int) (FederationServer::getParameter('limit') ?? Configuration::getServerConfiguration()->getListEvidenceMaxItems());
            $page = (int) (FederationServer::getParameter('page') ?? 1);
            $includeConfidential = filter_var(FederationServer::getParameter('include_confidential') ?? false, FILTER_VALIDATE_BOOLEAN);
            $categoryInput = FederationServer::getParameter('category');
            $category = $categoryInput !== null ? EvidenceCategory::tryFrom(strtoupper($categoryInput)) : null;
            $by = FederationServer::getParameter('by');
            $orderInput = FederationServer::getParameter('order');
            $order = $orderInput !== null ? OrderType::tryFrom(strtoupper($orderInput)) : null;

            if($includeConfidential)
            {
                if($authenticatedOperator === null)
                {
                    throw new RequestException(self::ERROR_CONFIDENTIAL_AUTH_REQUIRED, 401);
                }

                if(!$authenticatedOperator->hasManagementPermissions())
                {
                    throw new RequestException(self::ERROR_CONFIDENTIAL_PERMISSION, 403);
                }
            }

            if($limit < 1 || $limit > Configuration::getServerConfiguration()->getListEvidenceMaxItems())
            {
                $limit = Configuration::getServerConfiguration()->getListEvidenceMaxItems();
            }

            if($page < 1)
            {
                $page = 1;
            }

            try
            {
                $evidenceRecords = EvidenceManager::getEvidenceRecords($limit, $page, $includeConfidential, $category, $by, $order);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_RETRIEVE, 500, $e);
            }

            self::successResponse(array_map(fn($evidence) => $evidence->toArray(), $evidenceRecords));
        }

        /**
         * @inheritDoc
         */
        public static function getTags(): array
        {
            return ['Evidence'];
        }

        /**
         * @inheritDoc
         */
        public static function getSummary(): string
        {
            return 'List evidence';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Retrieves a paginated list of evidence records.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'listEvidence';
        }

        /**
         * @inheritDoc
         */
        public static function getParameters(): array
        {
            return [
                [
                    'name' => 'page',
                    'in' => 'query',
                    'required' => false,
                    'schema' => ['type' => 'integer', 'default' => 1],
                    'description' => 'Page number for pagination',
                ],
                [
                    'name' => 'limit',
                    'in' => 'query',
                    'required' => false,
                    'schema' => ['type' => 'integer', 'default' => 20],
                    'description' => 'Number of records per page',
                ],
                [
                    'name' => 'include_confidential',
                    'in' => 'query',
                    'required' => false,
                    'schema' => ['type' => 'boolean', 'default' => false],
                    'description' => 'Include confidential evidence (requires management permissions)',
                ],
                [
                    'name' => 'category',
                    'in' => 'query',
                    'description' => 'Filter evidence by category',
                    'required' => false,
                    'schema' => [
                        'type' => 'string',
                        'enum' => array_column(EvidenceCategory::cases(), 'value'),
                    ],
                ],
                [
                    'name' => 'by',
                    'in' => 'query',
                    'description' => 'Field to sort by',
                    'required' => false,
                    'schema' => [
                        'type' => 'string',
                        'enum' => array_column(EvidenceOrderType::cases(), 'value'),
                    ],
                ],
                [
                    'name' => 'order',
                    'in' => 'query',
                    'description' => 'Sort direction',
                    'required' => false,
                    'schema' => ['type' => 'string', 'enum' => array_column(OrderType::cases(), 'value')],
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
                    'description' => 'List of evidence records',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'array',
                                'items' => ['$ref' => EvidenceRecord::getReference()],
                            ],
                        ],
                    ],
                ],
                '401' => [
                    'description' => self::ERROR_AUTH_REQUIRED,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '403' => [
                    'description' => self::ERROR_CONFIDENTIAL_PERMISSION,
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

