<?php

    namespace FederationLib\Methods\Attachments;

    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\Managers\FileAttachmentManager;
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

    class DeleteAttachment extends RequestHandler implements RequestSpecificationInterface
    {
        private const string ERROR_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions to delete attachments';
        private const string ERROR_ATTACHMENT_UUID_REQUIRED = 'Attachment UUID is required';
        private const string ERROR_INVALID_ATTACHMENT_UUID = 'Invalid attachment UUID';
        private const string ERROR_ATTACHMENT_NOT_FOUND = 'Attachment not found';
        private const string ERROR_ASSOCIATED_EVIDENCE_NOT_FOUND = 'Associated evidence not found';
        private const string ERROR_UNABLE_TO_DELETE = 'Unable to delete file attachment';

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();

            if(!$authenticatedOperator->hasManagementPermissions())
            {
                throw new RequestException(self::ERROR_INSUFFICIENT_PERMISSIONS, 403);
            }

            if(!preg_match('#^/attachments/([a-fA-F0-9\-]{36})$#', FederationServer::getPath(), $matches))
            {
                throw new RequestException(self::ERROR_ATTACHMENT_UUID_REQUIRED, 400);
            }

            $attachmentUuid = $matches[1];
            if(!$attachmentUuid || !Validate::uuid($attachmentUuid))
            {
                throw new RequestException(self::ERROR_INVALID_ATTACHMENT_UUID, 400);
            }

            try
            {
                $existingAttachment = FileAttachmentManager::getRecord($attachmentUuid);
                if($existingAttachment === null)
                {
                    throw new RequestException(self::ERROR_ATTACHMENT_NOT_FOUND, 404);
                }

                $existingEvidence = EvidenceManager::getEvidence($existingAttachment->getEvidenceUuid());
                if($existingEvidence === null)
                {
                    throw new RequestException(self::ERROR_ASSOCIATED_EVIDENCE_NOT_FOUND, 404);
                }

                AuditLogManager::createEntry(AuditLogType::ATTACHMENT_DELETED, sprintf('Attachment %s deleted by operator %s',
                    $attachmentUuid,
                    $authenticatedOperator->getName()
                ), $authenticatedOperator->getUuid(), $existingEvidence->getEntityUuid(), null, $existingAttachment->getEvidenceUuid());
                FileAttachmentManager::deleteRecord($attachmentUuid); // This will delete the file automatically
            }
            catch(InvalidArgumentException $e)
            {
                throw new RequestException($e->getMessage(), 400, $e);
            }
            catch(DatabaseOperationException $e)
            {
                throw new RequestException(self::ERROR_UNABLE_TO_DELETE, 500, $e);
            }

            // Respond with the UUID of the newly created operator.
            self::successResponse();
        }

        /**
         * @inheritDoc
         */
        public static function getTags(): array
        {
            return ['Attachments'];
        }

        /**
         * @inheritDoc
         */
        public static function getSummary(): string
        {
            return 'Delete an attachment';
        }

        /**
         * @inheritDoc
         */
        public static function getDescription(): string
        {
            return 'Permanently deletes an attachment and its associated file. Management permissions are required.';
        }

        /**
         * @inheritDoc
         */
        public static function getOperationId(): string
        {
            return 'deleteAttachment';
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
                    'description' => self::ERROR_ATTACHMENT_UUID_REQUIRED,
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
                    'description' => 'Attachment deleted successfully',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => SuccessResponse::getReference()],
                        ],
                    ],
                ],
                '400' => [
                    'description' => self::ERROR_INVALID_ATTACHMENT_UUID,
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
                    'description' => self::ERROR_ATTACHMENT_NOT_FOUND . ' or ' . self::ERROR_ASSOCIATED_EVIDENCE_NOT_FOUND,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
                '500' => [
                    'description' => self::ERROR_UNABLE_TO_DELETE,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => ErrorResponse::getReference()],
                        ],
                    ],
                ],
            ];
        }
    }
