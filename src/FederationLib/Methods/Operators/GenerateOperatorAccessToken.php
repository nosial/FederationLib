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
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\ErrorResponse;

    class GenerateOperatorAccessToken extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to generate other operators Access Tokens';
        private const string ERROR_NOT_FOUND = 'Operator not found';
        private const string ERROR_CANNOT_GENERATE_ROOT = 'Cannot generate Access Token for root operator';
        private const string ERROR_UNABLE_TO_GENERATE = 'Unable to generate operator\'s Access token';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(preg_match('#^/operators/([a-fA-F0-9\-]{36})/refresh$#', FederationServer::getPath(), $matches))
            {
                $operatorUuid = $matches[1];
                // Ensure the authenticated operator has permission to generate other operators' Access Tokens.
                if($operatorUuid !== $authenticatedOperator->getUuid() && !$authenticatedOperator->hasOperatorPermissions())
                {
                    throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, HttpResponseCode::FORBIDDEN);
                }
            }
            else
            {
                $operatorUuid = $authenticatedOperator->getUuid();
            }

            try
            {
                if($operatorUuid !== $authenticatedOperator->getUuid())
                {
                    $existingOperator = OperatorManager::getOperator($operatorUuid);
                    if($existingOperator === null)
                    {
                        throw new RequestException(self::ERROR_NOT_FOUND, HttpResponseCode::NOT_FOUND);
                    }
                }
                else
                {
                    $existingOperator = $authenticatedOperator;
                }

                if(OperatorManager::isRootOperator($operatorUuid))
                {
                    throw new RequestException(self::ERROR_CANNOT_GENERATE_ROOT, HttpResponseCode::FORBIDDEN);
                }

                $newAccessToken = OperatorManager::newAccessToken($operatorUuid);
                AuditLogManager::createEntry(AuditLogType::OPERATOR_ACCESS_TOKEN_GENERATED, sprintf(
                    'Operator %s (%s) generated Access Token by %s',
                    $existingOperator->getName(),
                    $existingOperator->getUuid(),
                    $authenticatedOperator->getName()
                ), $authenticatedOperator->getUuid());
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_GENERATE, HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            self::successResponse($newAccessToken);
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
            return 'Generate operator access token';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Generates a new access token for an operator (or the authenticated operator if no UUID is provided). Cannot generate the root operator\'s token. Requires operator management permissions to generate another operator\'s token.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'generateOperatorAccessToken';
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
                    'description' => 'Operator UUID (optional, defaults to authenticated operator)',
                    'required' => false,
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
                    'description' => 'Access token generated successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => ['type' => 'string', 'description' => 'The new access token'],
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
                    'description' => self::ERROR_UNABLE_TO_GENERATE,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }
