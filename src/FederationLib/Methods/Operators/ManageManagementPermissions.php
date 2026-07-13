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

    class ManageManagementPermissions extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to manage permissions';
        private const string ERROR_MISSING_PARAMETERS = 'Missing required parameters';
        private const string ERROR_INVALID_UUID = 'Invalid operator UUID';
        private const string ERROR_NOT_FOUND = 'Operator not found';
        private const string ERROR_CANNOT_MODIFY_ROOT = 'Cannot modify permissions for the root operator';
        private const string ERROR_UNABLE_TO_MANAGE = 'Unable to manage operator\'s permissions';

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

            if(!preg_match('#^/operators/([a-fA-F0-9\-]{36})/management_permissions$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException(self::ERROR_MISSING_PARAMETERS, HttpResponseCode::BAD_REQUEST);
            }

            $operatorUuid = $matches[1];
            $enabledParam = FederationServer::getParameter('enabled');
            if($enabledParam === null)
            {
                throw new RequestException(self::ERROR_MISSING_PARAMETERS, HttpResponseCode::BAD_REQUEST);
            }
            $enabled = filter_var($enabledParam, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if($enabled === null)
            {
                throw new RequestException(self::ERROR_MISSING_PARAMETERS, HttpResponseCode::BAD_REQUEST);
            }

            if(!Validate::uuid($operatorUuid))
            {
                throw new RequestException(self::ERROR_INVALID_UUID, HttpResponseCode::BAD_REQUEST);
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
                    throw new RequestException(self::ERROR_CANNOT_MODIFY_ROOT, HttpResponseCode::FORBIDDEN);
                }

                OperatorManager::setManagementPermissions($operatorUuid, $enabled);
                AuditLogManager::createEntry(AuditLogType::OPERATOR_PERMISSIONS_CHANGED, sprintf(
                    'Operator %s %s management permissions by %s',
                    $targetOperator->getName(),
                    $enabled ? 'enabled' : 'disabled',
                    $authenticatedOperator->getName()
                ), $authenticatedOperator->getUuid());
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_MANAGE, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
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
            return 'Manage management permissions for an operator';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Enables or disables management permissions for an operator. Cannot modify permissions for the root operator. Requires operator management permissions.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'manageManagementPermissions';
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
                                'enabled' => [
                                    'type' => 'boolean',
                                    'description' => 'Whether management permissions should be enabled',
                                ],
                            ],
                            'required' => ['enabled'],
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
                    'description' => 'Management permissions updated successfully',
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
                    'description' => self::ERROR_UNABLE_TO_MANAGE,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }
