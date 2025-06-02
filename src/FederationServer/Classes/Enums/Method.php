<?php

    namespace FederationServer\Classes\Enums;

    use FederationServer\Exceptions\RequestException;
    use FederationServer\Methods\CreateOperator;
    use FederationServer\Methods\DeleteOperator;
    use FederationServer\Methods\DownloadAttachment;
    use FederationServer\Methods\UploadAttachment;

    enum Method
    {
        case CREATE_OPERATOR;
        case DELETE_OPERATOR;

        case UPLOAD_ATTACHMENT;
        case DOWNLOAD_ATTACHMENT;

        /**
         * Handles the request of the method
         *
         * @return void
         * @throws RequestException Thrown if there was an error while executing the request method
         */
        public function handleRequest(): void
        {
            switch($this)
            {
                case self::CREATE_OPERATOR:
                    CreateOperator::handleRequest();
                    break;
                case self::DELETE_OPERATOR:
                    DeleteOperator::handleRequest();
                    break;

                case self::UPLOAD_ATTACHMENT:
                    UploadAttachment::handleRequest();
                    break;
                case self::DOWNLOAD_ATTACHMENT:
                    DownloadAttachment::handleRequest();
                    break;
            }
        }

        /**
         * Handles the given input with a matching available method, returns null if no available match was found
         *
         * @param string $requestMethod The request method that was used to make the request
         * @param string $path The request path (Excluding the URI)
         * @return Method|null The matching method or null if no match was found
         */
        public static function matchHandle(string $requestMethod, string $path): ?Method
        {
            return match (true)
            {
                $requestMethod === 'POST' && $path === '/' => null,
                preg_match('#^/attachment/([a-fA-F0-9\-]{36,})$#', $path) => Method::DOWNLOAD_ATTACHMENT,
                ($requestMethod === 'POST' | $requestMethod === 'PUT') && $path === '/uploadAttachment' => Method::UPLOAD_ATTACHMENT,
                $requestMethod === 'POST' && $path === '/createOperator' => Method::CREATE_OPERATOR,
                $requestMethod === 'DELETE' && $path === '/deleteOperator' => Method::DELETE_OPERATOR,
                default => null,
            };

        }
    }
