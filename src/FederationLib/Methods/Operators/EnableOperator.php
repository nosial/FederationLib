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

    class EnableOperator extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to enable/disable operators';
        private const string ERROR_UUID_REQUIRED = 'Operator UUID is required';
        private const string ERROR_INVALID_UUID = 'A valid operator UUID is required';
        private const string ERROR_CANNOT_ENABLE_SELF = 'You cannot enable yourself';
        private const string ERROR_NOT_FOUND = 'Operator not found';
        private const string ERROR_CANNOT_ENABLE_ROOT = 'Cannot enable the root operator';
        private const string ERROR_ALREADY_ENABLED = 'Operator is already enabled';
        private const string ERROR_UNABLE_TO_ENABLE = 'Unable to enable operator';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();

            // Ensure the authenticated operator has permission to enable operators.
            if(!$authenticatedOperator->hasOperatorPermissions())
            {
                throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, HttpResponseCode::FORBIDDEN);
            }

            if(!preg_match('#^/operators/([a-fA-F0-9\-]{36})/enable$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException(self::ERROR_UUID_REQUIRED, HttpResponseCode::BAD_REQUEST);
            }

            $operatorUuid = $matches[1];
            if(!$operatorUuid || !Validate::uuid($operatorUuid))
            {
                throw new RequestException(self::ERROR_INVALID_UUID, HttpResponseCode::BAD_REQUEST);
            }

            if($operatorUuid === $authenticatedOperator->getUuid())
            {
                throw new RequestException(self::ERROR_CANNOT_ENABLE_SELF, HttpResponseCode::BAD_REQUEST);
            }

            try
            {
                $existingOperator = OperatorManager::getOperator($operatorUuid);
                if($existingOperator === null)
                {
                    throw new RequestException(self::ERROR_NOT_FOUND, HttpResponseCode::NOT_FOUND);
                }

                if(OperatorManager::isRootOperator($operatorUuid))
                {
                    throw new RequestException(self::ERROR_CANNOT_ENABLE_ROOT, HttpResponseCode::FORBIDDEN);
                }

                if(!$existingOperator->isDisabled())
                {
                    throw new RequestException(self::ERROR_ALREADY_ENABLED, HttpResponseCode::BAD_REQUEST);
                }

                OperatorManager::enableOperator($operatorUuid);
                AuditLogManager::createEntry(AuditLogType::OPERATOR_ENABLED, sprintf('Operator %s enabled by %s',
                    $existingOperator->getName(),
                    $authenticatedOperator->getName()
                ), $authenticatedOperator->getUuid());
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_ENABLE, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
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
            return 'Enable an operator';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Enables a previously disabled operator by UUID. Cannot enable the root operator or yourself. Requires operator management permissions.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'enableOperator';
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
                    'description' => 'Operator enabled successfully',
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
                    'description' => self::ERROR_UNABLE_TO_ENABLE,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }
