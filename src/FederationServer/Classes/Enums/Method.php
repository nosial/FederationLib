<?php

    namespace FederationServer\Classes\Enums;

    use FederationServer\Exceptions\RequestException;
    use FederationServer\Methods\CreateOperator;
    use FederationServer\Methods\DeleteOperator;
    use FederationServer\Methods\DownloadAttachment;
    use FederationServer\Methods\EnableOperator;
    use FederationServer\Methods\GetOperator;
    use FederationServer\Methods\RefreshOperatorApiKey;
    use FederationServer\Methods\UploadAttachment;

    enum Method
    {
        case CREATE_OPERATOR;
        case DELETE_OPERATOR;
        case ENABLE_OPERATOR;
        case GET_OPERATOR;
        case REFRESH_OPERATOR_API_KEY;

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
                case self::ENABLE_OPERATOR:
                    EnableOperator::handleRequest();
                    break;
                case self::GET_OPERATOR:
                    GetOperator::handleRequest();
                    break;
                case self::REFRESH_OPERATOR_API_KEY:
                    RefreshOperatorApiKey::handleRequest();
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
                ($requestMethod === 'POST' | $requestMethod === 'PUT') && $path === '/attachment/upload' => Method::UPLOAD_ATTACHMENT,

                $requestMethod === 'POST' && $path === '/operators/create' => Method::CREATE_OPERATOR,
                $requestMethod === 'DELETE' && $path === '/operators/delete' => Method::DELETE_OPERATOR,
                $requestMethod === 'GET' && $path === '/operators/get' => Method::GET_OPERATOR,
                $requestMethod === 'POST' && $path === '/operators/enable' => Method::ENABLE_OPERATOR,
                $requestMethod === 'POST' && $path === '/operators/refresh' => Method::REFRESH_OPERATOR_API_KEY,

                default => null,
            };

        }
    }
