<?php

    namespace FederationLib\Methods\Evidence;

    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Validate;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\SuccessResponse;
    use InvalidArgumentException;
    use FederationLib\Interfaces\RequestSpecificationInterface;

    class UpdateTag extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to manage evidence';
        private const string ERROR_UUID_REQUIRED = 'Evidence UUID is required';
        private const string ERROR_INVALID_UUID = 'Invalid evidence UUID';
        private const string ERROR_TAG_REQUIRED = 'Tag is required';
        private const string ERROR_NOT_FOUND = 'Evidence not found';
        private const string ERROR_UNABLE_TO_UPDATE = 'Unable to update evidence tag';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!$authenticatedOperator->hasOperatorPermissions())
            {
                throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, 403);
            }

            if(!preg_match('#^/evidence/([a-fA-F0-9\-]{36})/update_tag$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException(self::ERROR_UUID_REQUIRED, 400);
            }

            $evidenceUuid = $matches[1];
            if(!Validate::uuid($evidenceUuid))
            {
                throw new RequestException(self::ERROR_INVALID_UUID, 400);
            }

            $tag = FederationServer::getParameter('tag');
            if($tag === null)
            {
                throw new RequestException(self::ERROR_TAG_REQUIRED, 400);
            }

            try
            {
                $evidenceRecord = EvidenceManager::getEvidence($evidenceUuid);
                if($evidenceRecord === null)
                {
                    throw new RequestException(self::ERROR_NOT_FOUND, 404);
                }

                EvidenceManager::updateTag($evidenceUuid, $tag);
                AuditLogManager::createEntry(AuditLogType::EVIDENCE_UPDATED, sprintf(
                    'Tag updated for evidence %s by %s',
                    $evidenceUuid,
                    $authenticatedOperator->getName()
                ), $authenticatedOperator->getUuid(), $evidenceRecord->getEntityUuid(), null, $evidenceUuid);
            }
            catch(InvalidArgumentException $e)
            {
                throw new RequestException($e->getMessage(), 400, $e);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_UPDATE, 500, $e);
            }

            self::successResponse();
        }

        /**
         * @inheritDoc
         */
        public static function getTags(): array
        {
            return ['Evidence'];
        }

        /**
         * @inheritDoc
         */
        public static function getSummary(): string
        {
            return 'Update evidence tag';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Updates the tag of an evidence record. Requires operator permissions.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'updateEvidenceTag';
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
                    'required' => true,
                    'schema' => ['type' => 'string', 'format' => 'uuid'],
                    'description' => 'UUID of the evidence record to update',
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
                                'tag' => [
                                    'type' => 'string',
                                    'description' => 'The new tag name for the evidence',
                                ],
                            ],
                            'required' => ['tag'],
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
                    'description' => 'Tag updated successfully',
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
