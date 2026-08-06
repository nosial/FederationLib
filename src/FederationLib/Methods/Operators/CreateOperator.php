<?php

    namespace FederationLib\Methods\Operators;

    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\OperatorCreated;
    use InvalidArgumentException;
    use FederationLib\Interfaces\RequestSpecificationInterface;

    class CreateOperator extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to create operators';
        private const string ERROR_NAME_REQUIRED = 'Operator name is required';
        private const string ERROR_NAME_RESERVED = 'Operator name is reserved';
        private const string ERROR_UNABLE_TO_CREATE = 'Unable to create operator';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();

            // Ensure the authenticated operator has permission to create new operators.
            if(!$authenticatedOperator->hasOperatorPermissions())
            {
                throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, HttpResponseCode::FORBIDDEN);
            }

            if(!FederationServer::getParameter('name'))
            {
                throw new RequestException(self::ERROR_NAME_REQUIRED, HttpResponseCode::BAD_REQUEST);
            }

            if(strtolower(FederationServer::getParameter('name')) === 'root' || strtolower(FederationServer::getParameter('name')) === 'system')
            {
                throw new RequestException(self::ERROR_NAME_RESERVED, HttpResponseCode::BAD_REQUEST);
            }

            $name = FederationServer::getParameter('name');

            if(OperatorManager::operatorNameExists($name))
            {
                throw new RequestException('Operator name already exists', HttpResponseCode::CONFLICT);
            }

            try
            {
                $operatorCreated = OperatorManager::createOperator($name);
                AuditLogManager::createEntry(AuditLogType::OPERATOR_CREATED, sprintf('Operator %s (%s) created by %s',
                    FederationServer::getParameter('name'),
                    $operatorCreated->getUuid(),
                    $authenticatedOperator->getName()
                ), $authenticatedOperator->getUuid());
            }
            catch(InvalidArgumentException $e)
            {
                throw new RequestException($e->getMessage(), HttpResponseCode::BAD_REQUEST, $e);
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_CREATE, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            // Respond with the operator's UUID and raw Access Token.
            // The raw token is only ever returned here (and by the refresh endpoint),
            // never after the operator has been persisted.
            self::successResponse($operatorCreated, HttpResponseCode::CREATED);
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
            return 'Create an operator';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Creates a new operator with the given name. The response contains the new operator\'s UUID and its raw Access Token; the token is only returned here and by the refresh endpoint, and is stored server-side as its SHA-256 hash so it can never be retrieved again. Requires operator management permissions.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'createOperator';
        }

        /**
         * @inheritDoc
         */
        public static function getParameters(): array
        {
            return [];
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
                                'name' => [
                                    'type' => 'string',
                                    'description' => 'The name of the new operator',
                                ],
                            ],
                            'required' => ['name'],
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
                '201' => [
                    'description' => 'Operator created successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => OperatorCreated::getReference()],
                        ],
                    ],
                ],
                '400' => [
                    'description' => self::ERROR_NAME_REQUIRED,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '401' => [
                    'description' => 'Authentication required',
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
                '409' => [
                    'description' => 'Operator name already exists',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '500' => [
                    'description' => self::ERROR_UNABLE_TO_CREATE,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }
