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
    use FederationLib\Objects\OperatorRecord\OperatorConfiguration;

    class UpdateOperatorConfiguration extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Only the operator itself or an operator with operator permissions can change its configuration';
        private const string ERROR_INVALID_UUID = 'Invalid operator UUID';
        private const string ERROR_INVALID_BODY = 'The request must contain one or more of auto_assign_reports, auto_assign_automated_reports and is_bot, each a boolean';
        private const string ERROR_NOT_FOUND = 'Operator not found';
        private const string ERROR_CANNOT_MODIFY_ROOT = 'Cannot modify the configuration of the root or system operator';
        private const string ERROR_UNABLE_TO_MANAGE = 'Unable to update the operator\'s configuration';

        /**
         * The members of an OperatorConfiguration object
         */
        private const array MEMBERS = ['auto_assign_reports', 'auto_assign_automated_reports', 'is_bot'];

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!preg_match('#^/operators/([a-fA-F0-9\-]{36})/configuration$#', FederationServer::getPath(), $matches) || !Validate::uuid($matches[1]))
            {
                throw new RequestException(self::ERROR_INVALID_UUID, HttpResponseCode::BAD_REQUEST);
            }

            // An operator changes its own configuration, and operators with operator permissions that of any operator
            $operatorUuid = $matches[1];
            if($operatorUuid !== $authenticatedOperator->getUuid() && !$authenticatedOperator->hasOperatorPermissions())
            {
                throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, HttpResponseCode::FORBIDDEN);
            }
            $changes = self::parseBooleanMembers(self::getDecodedContent(), self::MEMBERS, self::ERROR_INVALID_BODY);

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

                OperatorManager::updateConfiguration($operatorUuid, $changes['auto_assign_reports'] ?? null, $changes['auto_assign_automated_reports'] ?? null, $changes['is_bot'] ?? null);
                AuditLogManager::createEntry(AuditLogType::OPERATOR_UPDATED, sprintf(
                    'Operator %s configuration updated by %s: %s',
                    $targetOperator->getName(),
                    $authenticatedOperator->getName(),
                    implode(', ', array_map(fn(string $member, bool $value) => sprintf('%s set to %s', $member, $value ? 'true' : 'false'), array_keys($changes), $changes))
                ), $authenticatedOperator->getUuid());

                $updatedOperator = OperatorManager::getOperator($operatorUuid);
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_MANAGE, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            self::successResponse(($updatedOperator ?? $targetOperator)->getConfiguration());
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
            return 'Update the configuration of an operator';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Changes the automatic report assignment opt-ins of an operator and whether it is a bot, whose evidence and reports are automated and whose evidence is created confidential. Settings that are not supplied are left unchanged. Cannot modify the root or system operator. An operator can change its own configuration; changing that of another operator requires operator permissions.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'updateOperatorConfiguration';
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
                            'properties' => OperatorConfiguration::getObjectProperties(),
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
                    'description' => 'The updated configuration of the operator',
                    'content' => ['application/json' => ['schema' => ['$ref' => OperatorConfiguration::getReference()]]],
                ],
                '400' => $error(self::ERROR_INVALID_BODY),
                '401' => $error('Authentication required'),
                '403' => $error(self::ERROR_INSUFFICIENT_PERMISSIONS),
                '404' => $error(self::ERROR_NOT_FOUND),
                '500' => $error(self::ERROR_UNABLE_TO_MANAGE),
            ];
        }
    }
