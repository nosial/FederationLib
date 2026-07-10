<?php

    namespace FederationLib\Methods\Operators;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\EvidenceRecord;

    class ListOperatorEvidence extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_AUTHENTICATION_REQUIRED = 'You must be authenticated to list evidence';
        private const string ERROR_UUID_REQUIRED = 'Operator UUID is required';
        private const string ERROR_NOT_FOUND = 'Operator not found';
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'You do not have permission to list evidence';
        private const string ERROR_UNABLE_TO_RETRIEVE = 'Unable to retrieve evidence';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();

            if(!Configuration::getServerConfiguration()->isEvidencePublic() && $authenticatedOperator === null)
            {
                throw new RequestException(self::ERROR_AUTHENTICATION_REQUIRED, HttpResponseCode::UNAUTHORIZED);
            }

            $limit = (int) (FederationServer::getParameter('limit') ?? Configuration::getServerConfiguration()->getListEvidenceMaxItems());
            $page = (int) (FederationServer::getParameter('page') ?? 1);
            $includeConfidential = filter_var(FederationServer::getParameter('include_confidential') ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            
            if($limit < 1 || $limit > Configuration::getServerConfiguration()->getListEvidenceMaxItems())
            {
                $limit = Configuration::getServerConfiguration()->getListEvidenceMaxItems();
            }

            if($page < 1)
            {
                $page = 1;
            }


            if(!preg_match('#^/operators/([a-fA-F0-9\-]{36})/evidence$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException(self::ERROR_UUID_REQUIRED, HttpResponseCode::BAD_REQUEST);
            }

            $operatorUuid = $matches[1];
            if(!$operatorUuid)
            {
                throw new RequestException(self::ERROR_UUID_REQUIRED, HttpResponseCode::BAD_REQUEST);
            }

            try
            {
                if(!OperatorManager::operatorExists($operatorUuid))
                {
                    throw new RequestException(self::ERROR_NOT_FOUND, HttpResponseCode::NOT_FOUND);
                }

                if($includeConfidential)
                {
                    if($authenticatedOperator === null)
                    {
                        throw new RequestException(self::ERROR_AUTHENTICATION_REQUIRED, HttpResponseCode::UNAUTHORIZED);
                    }

                    if(!$authenticatedOperator->hasManagementPermissions())
                    {
                        throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, HttpResponseCode::FORBIDDEN);
                    }
                }

                $evidenceRecords = EvidenceManager::getEvidenceByOperator($operatorUuid, $limit, $page, $includeConfidential);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_RETRIEVE, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            self::successResponse(array_map(fn($evidence) => $evidence->toArray(), $evidenceRecords));
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
            return 'List evidence for an operator';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Retrieves a paginated list of evidence records submitted by a specific operator. If evidence is not public, authentication is required.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'listOperatorEvidence';
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
                [
                    'name' => 'limit',
                    'in' => 'query',
                    'description' => 'Maximum number of evidence records to return',
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
                    'name' => 'include_confidential',
                    'in' => 'query',
                    'description' => 'Whether to include confidential evidence records',
                    'required' => false,
                    'schema' => ['type' => 'boolean'],
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
                '400' => [
                    'description' => self::ERROR_UUID_REQUIRED,
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
