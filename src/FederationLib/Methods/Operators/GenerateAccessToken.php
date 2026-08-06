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

    class GenerateAccessToken extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_CANNOT_GENERATE_ROOT = 'Cannot generate Access Token for a builtin operator';
        private const string ERROR_UNABLE_TO_GENERATE = 'Unable to generate operator\'s Access token';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();

            try
            {
                if(OperatorManager::isRootOperator($authenticatedOperator->getUuid()) || OperatorManager::isSystemOperator($authenticatedOperator->getUuid()))
                {
                    throw new RequestException(self::ERROR_CANNOT_GENERATE_ROOT, HttpResponseCode::FORBIDDEN);
                }

                $newAccessToken = OperatorManager::newAccessToken($authenticatedOperator->getUuid());
                AuditLogManager::createEntry(AuditLogType::OPERATOR_ACCESS_TOKEN_GENERATED, sprintf(
                    'Operator %s (%s) generated Access Token by %s',
                    $authenticatedOperator->getName(),
                    $authenticatedOperator->getUuid(),
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
            return 'Generate access token for the authenticated operator';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Generates a new access token for the currently authenticated operator. Any authenticated operator may refresh their own token without requiring operator management permissions. Cannot generate a token for a builtin (root/system) operator.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'generateAccessToken';
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
                '401' => [
                    'description' => 'Authentication required',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '403' => [
                    'description' => self::ERROR_CANNOT_GENERATE_ROOT,
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