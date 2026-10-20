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
    use FederationLib\Objects\OperatorRecord\OperatorPermissions;

    class UpdateOperatorPermissions extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to manage permissions';
        private const string ERROR_INVALID_UUID = 'Invalid operator UUID';
        private const string ERROR_INVALID_BODY = 'The request must contain one or more of client, management and operator, each a boolean';
        private const string ERROR_NOT_FOUND = 'Operator not found';
        private const string ERROR_CANNOT_MODIFY_ROOT = 'Cannot modify permissions for the root or system operator';
        private const string ERROR_MANAGEMENT_WITHOUT_CLIENT = 'Management permissions inherit client permissions, an operator with management permissions must keep client permissions';
        private const string ERROR_UNABLE_TO_MANAGE = 'Unable to update the operator\'s permissions';

        /**
         * The members of an OperatorPermissions object
         */
        private const array MEMBERS = ['client', 'management', 'operator'];

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

            if(!preg_match('#^/operators/([a-fA-F0-9\-]{36})/permissions$#', FederationServer::getPath(), $matches) || !Validate::uuid($matches[1]))
            {
                throw new RequestException(self::ERROR_INVALID_UUID, HttpResponseCode::BAD_REQUEST);
            }

            $operatorUuid = $matches[1];
            $changes = self::parseBody(self::getDecodedContent());

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

                // Management permissions inherit client permissions, an operator never keeps one without the other
                $management = $changes['management'] ?? $targetOperator->hasManagementPermissions();
                if(($changes['client'] ?? null) === false && $management)
                {
                    throw new RequestException(self::ERROR_MANAGEMENT_WITHOUT_CLIENT, HttpResponseCode::CONFLICT);
                }

                OperatorManager::updatePermissions($operatorUuid, $changes['client'] ?? null, $changes['management'] ?? null, $changes['operator'] ?? null);
                AuditLogManager::createEntry(AuditLogType::OPERATOR_UPDATED, sprintf(
                    'Operator %s permissions updated by %s: %s',
                    $targetOperator->getName(),
                    $authenticatedOperator->getName(),
                    implode(', ', array_map(fn(string $member, bool $value) => sprintf('%s %s', $member, $value ? 'granted' : 'revoked'), array_keys($changes), $changes))
                ), $authenticatedOperator->getUuid());

                $updatedOperator = OperatorManager::getOperator($operatorUuid);
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_MANAGE, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            self::successResponse(($updatedOperator ?? $targetOperator)->getPermissions());
        }

        /**
         * Parses the request body, a JSON object with one or more members of an OperatorPermissions object
         *
         * @param array|null $body The decoded JSON request body, null when the parameters are form-encoded
         * @return array<string, bool> The supplied members
         * @throws RequestException If the body has no member, or a member is unknown or not a boolean
         */
        private static function parseBody(?array $body): array
        {
            return self::parseBooleanMembers($body, self::MEMBERS, self::ERROR_INVALID_BODY);
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
            return 'Update the permissions of an operator';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Grants or revokes the client, management and operator permission groups of an operator, leaving the groups that are not supplied unchanged. Management permissions inherit client permissions, so an operator with management permissions keeps client permissions. Cannot modify the root or system operator. Requires operator permissions.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'updateOperatorPermissions';
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
                                'client' => ['type' => 'boolean', 'description' => 'Whether the operator holds client permissions'],
                                'management' => ['type' => 'boolean', 'description' => 'Whether the operator holds management permissions'],
                                'operator' => ['type' => 'boolean', 'description' => 'Whether the operator holds operator permissions'],
                            ],
                            'additionalProperties' => false,
                            'minProperties' => 1,
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
            $error = fn(string $description) => [
                'description' => $description,
                'content' => ['application/json' => ['schema' => ['$ref' => ErrorResponse::getReference()]]],
            ];

            return [
                '200' => [
                    'description' => 'The updated permissions of the operator',
                    'content' => ['application/json' => ['schema' => ['$ref' => OperatorPermissions::getReference()]]],
                ],
                '400' => $error(self::ERROR_INVALID_BODY),
                '401' => $error('Authentication required'),
                '403' => $error(self::ERROR_INSUFFICIENT_PERMISSIONS),
                '404' => $error(self::ERROR_NOT_FOUND),
                '409' => $error(self::ERROR_MANAGEMENT_WITHOUT_CLIENT),
                '500' => $error(self::ERROR_UNABLE_TO_MANAGE),
            ];
        }
    }
