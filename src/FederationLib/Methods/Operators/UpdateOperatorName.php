<?php

    namespace FederationLib\Methods\Operators;

    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\OperatorManager;
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

    class UpdateOperatorName extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to update operator name';
        private const string ERROR_MISSING_PARAMETERS = 'Missing required parameters';
        private const string ERROR_INVALID_UUID = 'Invalid operator UUID';
        private const string ERROR_NOT_FOUND = 'Operator not found';
        private const string ERROR_CANNOT_MODIFY_BUILTIN = 'Cannot modify name of a builtin operator';
        private const string ERROR_NAME_EXISTS = 'Operator name is already in use';
        private const string ERROR_NAME_TOO_LONG = 'Operator name cannot exceed 32 characters';
        private const string ERROR_UNABLE_TO_UPDATE = 'Unable to update operator name';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!$authenticatedOperator->hasOperatorPermissions())
            {
                throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, HttpResponseCode::FORBIDDEN);
            }

            if(!preg_match('#^/operators/([a-fA-F0-9\-]{36})/update-name$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException(self::ERROR_MISSING_PARAMETERS, HttpResponseCode::BAD_REQUEST);
            }

            $operatorUuid = $matches[1];
            if(!Validate::uuid($operatorUuid))
            {
                throw new RequestException(self::ERROR_INVALID_UUID, HttpResponseCode::BAD_REQUEST);
            }

            $name = FederationServer::getParameter('name');
            if($name === null || empty($name))
            {
                throw new RequestException(self::ERROR_MISSING_PARAMETERS, HttpResponseCode::BAD_REQUEST);
            }

            if(strlen($name) > 32)
            {
                throw new RequestException(self::ERROR_NAME_TOO_LONG, HttpResponseCode::BAD_REQUEST);
            }

            try
            {
                $targetOperator = OperatorManager::getOperator($operatorUuid);
                if($targetOperator === null)
                {
                    throw new RequestException(self::ERROR_NOT_FOUND, HttpResponseCode::NOT_FOUND);
                }

                if(OperatorManager::isRootOperator($operatorUuid) || OperatorManager::isSystemOperator($operatorUuid))
                {
                    throw new RequestException(self::ERROR_CANNOT_MODIFY_BUILTIN, HttpResponseCode::FORBIDDEN);
                }

                if(OperatorManager::operatorNameExists($name))
                {
                    throw new RequestException(self::ERROR_NAME_EXISTS, HttpResponseCode::CONFLICT);
                }

                OperatorManager::updateOperatorName($operatorUuid, $name);
                AuditLogManager::createEntry(AuditLogType::OPERATOR_NAME_CHANGED, sprintf(
                    'Operator %s renamed to %s by %s',
                    $targetOperator->getName(),
                    $name,
                    $authenticatedOperator->getName()
                ), $authenticatedOperator->getUuid());
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_UPDATE, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            self::successResponse();
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
            return 'Update an operator name';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Updates the name of an operator by UUID. The name must be unique and cannot exceed 32 characters. Cannot modify builtin operators (root/system). Requires operator management permissions.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'updateOperatorName';
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
                    'description' => 'Operator UUID',
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
                                'name' => [
                                    'type' => 'string',
                                    'description' => 'The new name for the operator (max 32 characters)',
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
                '200' => [
                    'description' => 'Operator name updated successfully',
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
                '409' => [
                    'description' => self::ERROR_NAME_EXISTS,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '500' => [
                    'description' => self::ERROR_UNABLE_TO_UPDATE,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }