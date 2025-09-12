<?php

    namespace FederationLib\Enums;

    use FederationLib\Classes\Logger;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\Methods\Attachments\DeleteAttachment;
    use FederationLib\Methods\Attachments\DownloadAttachment;
    use FederationLib\Methods\Attachments\GetAttachmentInfo;
    use FederationLib\Methods\Attachments\UploadAttachment;
    use FederationLib\Methods\Audit\ListAuditLogs;
    use FederationLib\Methods\Audit\ViewAuditEntry;
    use FederationLib\Methods\Blacklist\BlacklistAttachEvidence;
    use FederationLib\Methods\Blacklist\BlacklistEntity;
    use FederationLib\Methods\Blacklist\DeleteBlacklist;
    use FederationLib\Methods\Blacklist\GetBlacklistRecord;
    use FederationLib\Methods\Blacklist\LiftBlacklist;
    use FederationLib\Methods\Blacklist\ListBlacklist;
    use FederationLib\Methods\Entities\DeleteEntity;
    use FederationLib\Methods\Entities\GetEntityRecord;
    use FederationLib\Methods\Entities\ListEntities;
    use FederationLib\Methods\Entities\ListEntityAuditLogs;
    use FederationLib\Methods\Entities\ListEntityBlacklistRecords;
    use FederationLib\Methods\Entities\ListEntityEvidence;
    use FederationLib\Methods\Entities\PushEntity;
    use FederationLib\Methods\Entities\QueryEntity;
    use FederationLib\Methods\Evidence\DeleteEvidence;
    use FederationLib\Methods\Evidence\GetEvidenceRecord;
    use FederationLib\Methods\Evidence\ListEvidence;
    use FederationLib\Methods\Evidence\SubmitEvidence;
    use FederationLib\Methods\Evidence\UpdateConfidentiality;
    use FederationLib\Methods\GetServerInformation;
    use FederationLib\Methods\Operators\CreateOperator;
    use FederationLib\Methods\Operators\DeleteOperator;
    use FederationLib\Methods\Operators\DisableOperator;
    use FederationLib\Methods\Operators\EnableOperator;
    use FederationLib\Methods\Operators\GetOperator;
    use FederationLib\Methods\Operators\GetSelfOperator;
    use FederationLib\Methods\Operators\ListOperatorAuditLogs;
    use FederationLib\Methods\Operators\ListOperatorBlacklist;
    use FederationLib\Methods\Operators\ListOperatorEvidence;
    use FederationLib\Methods\Operators\ListOperators;
    use FederationLib\Methods\Operators\ManageBlacklistPermission;
    use FederationLib\Methods\Operators\ManageClientPermission;
    use FederationLib\Methods\Operators\ManageOperatorsPermission;
    use FederationLib\Methods\Operators\RefreshOperatorApiKey;

    enum Method
    {
        case GET_SERVER_INFORMATION;

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
        case LIST_OPERATOR_EVIDENCE;
        case LIST_OPERATOR_AUDIT_LOGS;
        case LIST_OPERATOR_BLACKLIST;

        case GET_ENTITY_RECORD;
        case DELETE_ENTITY;
        case LIST_ENTITIES;
        case PUSH_ENTITY;
        case LIST_ENTITY_EVIDENCE;
        case LIST_ENTITY_AUDIT_LOGS;
        case LIST_ENTITY_BLACKLIST_RECORDS;
        case QUERY_ENTITY;

        case LIST_EVIDENCE;
        case SUBMIT_EVIDENCE;
        case GET_EVIDENCE_RECORD;
        case UPDATE_CONFIDENTIALITY;
        case DELETE_EVIDENCE;

        case LIST_BLACKLIST;
        case BLACKLIST_ENTITY;
        case DELETE_BLACKLIST;
        case LIFT_BLACKLIST;
        case GET_BLACKLIST_RECORD;

        case UPLOAD_ATTACHMENT;
        case DOWNLOAD_ATTACHMENT;
        case GET_ATTACHMENT_INFO;
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
                case self::GET_SERVER_INFORMATION:
                    GetServerInformation::handleRequest();
                    break;

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
                case self::GET_ATTACHMENT_INFO:
                    GetAttachmentInfo::handleRequest();
                    break;

                case self::LIST_ENTITIES:
                    ListEntities::handleRequest();
                    break;
                case self::GET_ENTITY_RECORD:
                    GetEntityRecord::handleRequest();
                    break;
                case self::DELETE_ENTITY:
                    DeleteEntity::handleRequest();
                    break;
                case self::PUSH_ENTITY:
                    PushEntity::handleRequest();
                    break;
                case self::LIST_ENTITY_EVIDENCE:
                    ListEntityEvidence::handleRequest();
                    break;
                case self::LIST_ENTITY_AUDIT_LOGS:
                    ListEntityAuditLogs::handleRequest();
                    break;
                case self::LIST_ENTITY_BLACKLIST_RECORDS:
                    ListEntityBlacklistRecords::handleRequest();
                    break;
                case self::QUERY_ENTITY:
                    QueryEntity::handleRequest();
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
                case self::LIST_OPERATOR_EVIDENCE:
                    ListOperatorEvidence::handleRequest();
                    break;
                case self::LIST_OPERATOR_AUDIT_LOGS:
                    ListOperatorAuditLogs::handleRequest();
                    break;
                case self::LIST_OPERATOR_BLACKLIST:
                    ListOperatorBlacklist::handleRequest();
                    break;

                case self::LIST_EVIDENCE:
                    ListEvidence::handleRequest();
                    break;
                case self::SUBMIT_EVIDENCE:
                    SubmitEvidence::handleRequest();
                    break;
                case self::GET_EVIDENCE_RECORD:
                    GetEvidenceRecord::handleRequest();
                    break;
                case self::UPDATE_CONFIDENTIALITY:
                    UpdateConfidentiality::handleRequest();
                    break;
                case self::DELETE_EVIDENCE:
                    DeleteEvidence::handleRequest();
                    break;

                case self::LIST_BLACKLIST:
                    ListBlacklist::handleRequest();
                    break;
                case self::BLACKLIST_ENTITY:
                    BlacklistEntity::handleRequest();
                    break;
                case self::DELETE_BLACKLIST:
                    DeleteBlacklist::handleRequest();
                    break;
                case self::LIFT_BLACKLIST:
                    LiftBlacklist::handleRequest();
                    break;
                case self::GET_BLACKLIST_RECORD:
                    GetBlacklistRecord::handleRequest();
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
            Logger::log()->debug(sprintf("Handling request [%s] %s", $requestMethod, $path));

            return match (true)
            {
                $path === '/' && $requestMethod === 'GET' => Method::LIST_AUDIT_LOGS,
                $path === '/info' && $requestMethod === 'GET' => Method::GET_SERVER_INFORMATION,
                preg_match('#^/audit/([a-fA-F0-9\-]{36})$#', $path) && $requestMethod === 'GET' => Method::VIEW_AUDIT_ENTRY,

                preg_match('#^/attachments/([a-fA-F0-9\-]{36})$#', $path) && $requestMethod === 'GET' => Method::DOWNLOAD_ATTACHMENT,
                preg_match('#^/attachments/([a-fA-F0-9\-]{36})$#', $path) && $requestMethod === 'DELETE' => Method::DELETE_ATTACHMENT,
                preg_match('#^/attachments/([a-fA-F0-9\-]{36})/info$#', $path) && $requestMethod === 'GET' => Method::GET_ATTACHMENT_INFO,
                $path === '/attachments' && ($requestMethod === 'POST' || $requestMethod === 'PUT')  => Method::UPLOAD_ATTACHMENT,

                $path === '/entities' && $requestMethod === 'GET' => Method::LIST_ENTITIES,
                $path === '/entities' && $requestMethod === 'POST' => Method::PUSH_ENTITY,
                preg_match('#^/entities/([a-fA-F0-9\-]{36})$#', $path) && $requestMethod === 'GET' => Method::GET_ENTITY_RECORD,
                preg_match('#^/entities/([a-fA-F0-9\-]{36})$#', $path) && $requestMethod === 'DELETE' => Method::DELETE_ENTITY,
                preg_match('#^/entities/([a-fA-F0-9\-]{36})/evidence$#', $path) && $requestMethod === 'GET' => Method::LIST_ENTITY_EVIDENCE,
                preg_match('#^/entities/([a-fA-F0-9\-]{36})/audit$#', $path) && $requestMethod === 'GET' => Method::LIST_ENTITY_AUDIT_LOGS,
                preg_match('#^/entities/([a-fA-F0-9\-]{36})/blacklist$#', $path) && $requestMethod === 'GET' => Method::LIST_ENTITY_BLACKLIST_RECORDS,
                preg_match('#^/entities/([a-fA-F0-9\-]{36})/query$#', $path) && $requestMethod === 'GET' => Method::QUERY_ENTITY,
                // SHA-256 hash of the entity ID is used for the blacklist
                preg_match('#^/entities/([a-f0-9\-]{64})$#', $path) && $requestMethod === 'GET' => Method::GET_ENTITY_RECORD,
                preg_match('#^/entities/([a-f0-9\-]{64})$#', $path) && $requestMethod === 'DELETE' => Method::DELETE_ENTITY,
                preg_match('#^/entities/([a-f0-9\-]{64})/evidence$#', $path) && $requestMethod === 'GET' => Method::LIST_ENTITY_EVIDENCE,
                preg_match('#^/entities/([a-f0-9\-]{64})/blacklist$#', $path) && $requestMethod === 'GET' => Method::LIST_ENTITY_BLACKLIST_RECORDS,
                preg_match('#^/entities/([a-f0-9\-]{64})/audit$#', $path) && $requestMethod === 'GET' => Method::LIST_ENTITY_AUDIT_LOGS,
                preg_match('#^/entities/([a-f0-9\-]{64})/query$#', $path) && $requestMethod === 'GET' => Method::QUERY_ENTITY,

                $path === '/blacklist' && $requestMethod === 'GET' => Method::LIST_BLACKLIST,
                $path === '/blacklist' && $requestMethod === 'POST' => Method::BLACKLIST_ENTITY,
                preg_match('#^/blacklist/([a-fA-F0-9\-]{36})$#', $path) && $requestMethod === 'GET' => Method::GET_BLACKLIST_RECORD,
                preg_match('#^/blacklist/([a-fA-F0-9\-]{36})$#', $path) && $requestMethod === 'DELETE' => Method::DELETE_BLACKLIST,
                preg_match('#^/blacklist/([a-fA-F0-9\-]{36})/lift$#', $path) && $requestMethod === 'POST' => Method::LIFT_BLACKLIST,

                $path === '/evidence' && $requestMethod === 'GET' => Method::LIST_EVIDENCE,
                $path === '/evidence' && $requestMethod === 'POST' => Method::SUBMIT_EVIDENCE,
                preg_match('#^/evidence/([a-fA-F0-9\-]{36})$#', $path) && $requestMethod === 'GET' => Method::GET_EVIDENCE_RECORD,
                preg_match('#^/evidence/([a-fA-F0-9\-]{36})/update_confidentiality$#', $path) && $requestMethod === 'POST' => Method::UPDATE_CONFIDENTIALITY,
                preg_match('#^/evidence/([a-fA-F0-9\-]{36})$#', $path) && $requestMethod === 'DELETE' => Method::DELETE_EVIDENCE,

                $path === '/operators' && $requestMethod === 'GET' => Method::LIST_OPERATORS,
                $path === '/operators' && $requestMethod === 'POST' => Method::CREATE_OPERATOR,
                $path === '/operators/self' && $requestMethod === 'GET' => Method::GET_SELF_OPERATOR,
                $path === '/operators/refresh' && $requestMethod === 'POST' => Method::REFRESH_OPERATOR_API_KEY,
                preg_match('#^/operators/([a-fA-F0-9\-]{36})$#', $path) && $requestMethod === 'GET' => Method::GET_OPERATOR,
                preg_match('#^/operators/([a-fA-F0-9\-]{36})$#', $path) && $requestMethod === 'DELETE' => Method::DELETE_OPERATOR,
                preg_match('#^/operators/([a-fA-F0-9\-]{36})/enable$#', $path) && $requestMethod === 'POST' => Method::ENABLE_OPERATOR,
                preg_match('#^/operators/([a-fA-F0-9\-]{36})/disable$#', $path) && $requestMethod === 'POST' => Method::DISABLE_OPERATOR,
                preg_match('#^/operators/([a-fA-F0-9\-]{36})/refresh$#', $path) && $requestMethod === 'POST' => Method::REFRESH_OPERATOR_API_KEY,
                preg_match('#^/operators/([a-fA-F0-9\-]{36})/manage_operators$#', $path) && $requestMethod === 'POST' => Method::MANAGE_OPERATORS_PERMISSION,
                preg_match('#^/operators/([a-fA-F0-9\-]{36})/manage_blacklist$#', $path) && $requestMethod === 'POST' => Method::MANAGE_BLACKLIST_PERMISSION,
                preg_match('#^/operators/([a-fA-F0-9\-]{36})/manage_client$#', $path) && $requestMethod === 'POST' => Method::MANAGE_CLIENT_PERMISSION,
                preg_match('#^/operators/([a-fA-F0-9\-]{36})/evidence$#', $path) && $requestMethod === 'GET' => Method::LIST_OPERATOR_EVIDENCE,
                preg_match('#^/operators/([a-fA-F0-9\-]{36})/audit$#', $path) && $requestMethod === 'GET' => Method::LIST_OPERATOR_AUDIT_LOGS,
                preg_match('#^/operators/([a-fA-F0-9\-]{36})/blacklist$#', $path) && $requestMethod === 'GET' => Method::LIST_OPERATOR_BLACKLIST,

                default => null,
            };

        }
    }
