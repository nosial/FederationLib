<?php

    namespace FederationLib\Methods\Entities;

    use Exception;
    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\ReportManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\Categories\ReportCategory;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\ReportRecord;

    class ListEntityReports extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_AUTHENTICATION_REQUIRED = 'Public reports are disabled and no entity is authenticated';
        private const string ERROR_IDENTIFIER_REQUIRED = 'Entity identifier UUID/SHA-256 is required';
        private const string ERROR_NOT_FOUND = 'Entity not found';
        private const string ERROR_FAILED_TO_GET_ENTITY = 'Failed to get entity';
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
            $category = $categoryInput !== null ? ReportCategory::tryFrom(strtoupper($categoryInput)) : null;

            if(
                !preg_match('#^/entities/([a-fA-F0-9\-]{36})/reports$#', FederationServer::getPath(), $matches) &&
                !preg_match('#^/entities/([a-f0-9\-]{64})/reports$#', FederationServer::getPath(), $matches) &&
                !preg_match('#^/entities/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/reports$#', FederationServer::getPath(), $matches)
            )
            {
                throw new RequestException(self::ERROR_IDENTIFIER_REQUIRED, HttpResponseCode::BAD_REQUEST);
            }

            $entity = $matches[1];
            if(!$entity)
            {
                throw new RequestException(self::ERROR_IDENTIFIER_REQUIRED, HttpResponseCode::BAD_REQUEST);
            }

            try
            {
                if(Utilities::isUuid($entity))
                {
                    $entityRecord = EntitiesManager::getEntityByUuid($entity);
                    $entityUuid = $entityRecord?->getUuid();
                }
                elseif(Utilities::isSha256($entity))
                {
                    $entityUuid = EntitiesManager::getEntityByHash($entity)?->getUuid();
                }
                elseif(Utilities::isEntityAddress($entity))
                {
                    $parsed = Utilities::parseEntityAddress($entity);
                    $hash = Utilities::hashEntity($parsed['host'], $parsed['id']);
                    $entityUuid = EntitiesManager::getEntityByHash($hash)?->getUuid();
                }
                else
                {
                    $entityUuid = null;
                }

                if ($entityUuid === null)
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
                throw new RequestException(self::ERROR_FAILED_TO_GET_ENTITY, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            try
            {
                self::successResponse(array_map(fn($report) => $report->toArray(),
                    ReportManager::getReportsByReportingEntity($entityUuid, $limit, $page, $category))
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
            return 'List reports for an entity';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Retrieves a paginated list of reports submitted against a specific entity. Reports must be public or the operator must be authenticated.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'listEntityReports';
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
                    'description' => 'List of reports for the entity',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'array',
                                'items' => ['$ref' => ReportRecord::getReference()],
                            ],
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
