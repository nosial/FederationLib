<?php

    namespace FederationLib\Methods\Attachments;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\Managers\FileAttachmentManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;

    class ListAttachments extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::getAuthenticatedOperator();
            if($authenticatedOperator === null)
            {
                throw new RequestException('Authentication is required to list attachments', HttpResponseCode::UNAUTHORIZED);
            }

            if(!$authenticatedOperator->canManageBlacklist())
            {
                throw new RequestException('Insufficient permissions to list attachments', HttpResponseCode::FORBIDDEN);
            }

            $limit = (int) (FederationServer::getParameter('limit') ?? Configuration::getServerConfiguration()->getListAttachmentsMaxItems());
            $page = (int) (FederationServer::getParameter('page') ?? 1);

            if($limit < 1 || $limit > Configuration::getServerConfiguration()->getListAttachmentsMaxItems())
            {
                $limit = Configuration::getServerConfiguration()->getListAttachmentsMaxItems();
            }

            if($page < 1)
            {
                $page = 1;
            }

            try
            {
                $attachmentRecords = FileAttachmentManager::getAttachmentRecords($limit, $page);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Unable to retrieve attachment records', HttpResponseCode::INTERNAL_SERVER_ERROR, $e);
            }

            self::successResponse(array_map(fn($attachment) => $attachment->toArray(), $attachmentRecords));
        }
    }
