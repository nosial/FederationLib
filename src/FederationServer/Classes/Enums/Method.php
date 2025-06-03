<?php

    namespace FederationServer\Classes\Enums;

    use FederationServer\Exceptions\RequestException;
    use FederationServer\Methods\Attachments\DeleteAttachment;
    use FederationServer\Methods\Attachments\DownloadAttachment;
    use FederationServer\Methods\Attachments\UploadAttachment;
    use FederationServer\Methods\Audit\ListAuditLogs;
    use FederationServer\Methods\Audit\ViewAuditEntry;
    use FederationServer\Methods\Entities\GetEntity;
    use FederationServer\Methods\Entities\ListEntities;
    use FederationServer\Methods\Entities\QueryEntity;
    use FederationServer\Methods\Operators\CreateOperator;
    use FederationServer\Methods\Operators\DeleteOperator;
    use FederationServer\Methods\Operators\DisableOperator;
    use FederationServer\Methods\Operators\EnableOperator;
    use FederationServer\Methods\Operators\GetOperator;
    use FederationServer\Methods\Operators\GetSelfOperator;
    use FederationServer\Methods\Operators\ListOperators;
    use FederationServer\Methods\Operators\ManageBlacklistPermission;
    use FederationServer\Methods\Operators\ManageClientPermission;
    use FederationServer\Methods\Operators\ManageOperatorsPermission;
    use FederationServer\Methods\Operators\RefreshOperatorApiKey;

    enum Method
    {
        case LIST_AUDIT_LOGS;
        case VIEW_AUDIT_ENTRY;

        case LIST_OPERATORS;
        case CREATE_OPERATOR;
        case GET_SELF_OPERATOR;
        case DELETE_OPERATOR;
        case ENABLE_OPERATOR;
        case DISABLE_OPERATOR;
        case GET_OPERATOR;
        case REFRESH_OPERATOR_API_KEY;
        case MANAGE_OPERATORS_PERMISSION;
        case MANAGE_BLACKLIST_PERMISSION;
        case MANAGE_CLIENT_PERMISSION;

        case GET_ENTITY;
        case LIST_ENTITIES;
        case QUERY_ENTITY;
        case PUSH_ENTITY;

        case UPLOAD_ATTACHMENT;
        case DOWNLOAD_ATTACHMENT;
        case DELETE_ATTACHMENT;

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
                case self::LIST_AUDIT_LOGS:
                    ListAuditLogs::handleRequest();
                    break;
                case self::VIEW_AUDIT_ENTRY:
                    ViewAuditEntry::handleRequest();
                    break;

                case self::UPLOAD_ATTACHMENT:
                    UploadAttachment::handleRequest();
                    break;
                case self::DOWNLOAD_ATTACHMENT:
                    DownloadAttachment::handleRequest();
                    break;
                case self::DELETE_ATTACHMENT:
                    DeleteAttachment::handleRequest();
                    break;

                case self::LIST_ENTITIES:
                    ListEntities::handleRequest();
                    break;
                case self::QUERY_ENTITY:
                    QueryEntity::handleRequest();
                    break;
                case self::GET_ENTITY:
                    GetEntity::handleRequest();
                    break;
                case self::PUSH_ENTITY:
                    PushEntity::handleRequest();
                    break;

                case self::LIST_OPERATORS:
                    ListOperators::handleRequest();
                    break;
                case self::GET_OPERATOR:
                    GetOperator::handleRequest();
                    break;
                case self::CREATE_OPERATOR:
                    CreateOperator::handleRequest();
                    break;
                case self::GET_SELF_OPERATOR:
                    GetSelfOperator::handleRequest();
                    break;
                case self::DELETE_OPERATOR:
                    DeleteOperator::handleRequest();
                    break;
                case self::ENABLE_OPERATOR:
                    EnableOperator::handleRequest();
                    break;
                case self::DISABLE_OPERATOR:
                    DisableOperator::handleRequest();
                    break;
                case self::REFRESH_OPERATOR_API_KEY:
                    RefreshOperatorApiKey::handleRequest();
                    break;
                case self::MANAGE_OPERATORS_PERMISSION:
                    ManageOperatorsPermission::handleRequest();
                    break;
                case self::MANAGE_BLACKLIST_PERMISSION:
                    ManageBlacklistPermission::handleRequest();
                    break;
                case self::MANAGE_CLIENT_PERMISSION:
                    ManageClientPermission::handleRequest();
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
                $path === '/' && $requestMethod === 'GET' => Method::LIST_AUDIT_LOGS,
                preg_match('#^/audit/([a-fA-F0-9\-]{36,})$#', $path) && $requestMethod === 'GET' => Method::VIEW_AUDIT_ENTRY,

                preg_match('#^/attachments/([a-fA-F0-9\-]{36,})$#', $path) && $requestMethod === 'GET' => Method::DOWNLOAD_ATTACHMENT,
                preg_match('#^/attachments/([a-fA-F0-9\-]{36,})$#', $path) && $requestMethod === 'DELETE' => Method::DELETE_ATTACHMENT,
                $path === '/attachments' && ($requestMethod === 'POST' || $requestMethod === 'PUT')  => Method::UPLOAD_ATTACHMENT,

                $path === '/entities' && $requestMethod === 'GET' => Method::LIST_ENTITIES,
                $path === '/entities' && $requestMethod === 'POST' => Method::PUSH_ENTITY,
                preg_match('#^/entities/([a-fA-F0-9\-]{36,})$#', $path) && $requestMethod === 'GET' => Method::GET_ENTITY,
                $path === '/entities/query' && $requestMethod === 'POST' => Method::QUERY_ENTITY,

                $path === '/operators' && $requestMethod === 'GET' => Method::LIST_OPERATORS,
                $path === '/operators' && $requestMethod === 'POST' => Method::CREATE_OPERATOR,
                $path === '/operators/self' && $requestMethod === 'GET' => Method::GET_SELF_OPERATOR,
                preg_match('#^/operators/([a-fA-F0-9\-]{36,})$#', $path) && $requestMethod === 'GET' => Method::GET_OPERATOR,
                preg_match('#^/operators/([a-fA-F0-9\-]{36,})$#', $path) && $requestMethod === 'DELETE' => Method::DELETE_OPERATOR,
                preg_match('#^/operators/([a-fA-F0-9\-]{36,})/enable$#', $path) && $requestMethod === 'POST' => Method::ENABLE_OPERATOR,
                preg_match('#^/operators/([a-fA-F0-9\-]{36,})/disable$#', $path) && $requestMethod === 'POST' => Method::DISABLE_OPERATOR,
                preg_match('#^/operators/([a-fA-F0-9\-]{36,})/refresh$#', $path) && $requestMethod === 'POST' => Method::REFRESH_OPERATOR_API_KEY,
                preg_match('#^/operators/([a-fA-F0-9\-]{36,})/manage_operators$#', $path) && $requestMethod === 'POST' => Method::MANAGE_OPERATORS_PERMISSION,
                preg_match('#^/operators/([a-fA-F0-9\-]{36,})/manage_blacklist$#', $path) && $requestMethod === 'POST' => Method::MANAGE_BLACKLIST_PERMISSION,
                preg_match('#^/operators/([a-fA-F0-9\-]{36,})/manage_client$#', $path) && $requestMethod === 'POST' => Method::MANAGE_CLIENT_PERMISSION,

                default => null,
            };

        }
    }
