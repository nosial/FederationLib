<?php

    namespace FederationLib\Enums;

    use FederationLib\Classes\Logger;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\Methods\Attachments\DeleteAttachment;
    use FederationLib\Methods\Attachments\DownloadAttachment;
    use FederationLib\Methods\Attachments\GetAttachmentInfo;
    use FederationLib\Methods\Attachments\ListAttachments;
    use FederationLib\Methods\Attachments\UploadAttachment;
    use FederationLib\Methods\Audit\ListAuditLogs;
    use FederationLib\Methods\Audit\ViewAuditEntry;
    use FederationLib\Methods\Blacklist\BlacklistEntity;
    use FederationLib\Methods\Blacklist\DeleteBlacklist;
    use FederationLib\Methods\Blacklist\GetBlacklistRecord;
    use FederationLib\Methods\Blacklist\LiftBlacklist;
    use FederationLib\Methods\Blacklist\ListBlacklist;
    use FederationLib\Methods\Entities\ClearRelationship;
    use FederationLib\Methods\Entities\ClearReputation;
    use FederationLib\Methods\Entities\DeleteEntity;
    use FederationLib\Methods\Entities\GetEntityRecord;
    use FederationLib\Methods\Entities\ListEntities;
    use FederationLib\Methods\Entities\ListEntityAuditLogs;
    use FederationLib\Methods\Entities\ListEntityBlacklistRecords;
    use FederationLib\Methods\Entities\ListEntityEvidence;
    use FederationLib\Methods\Entities\ListEntityReports;
    use FederationLib\Methods\Entities\PushEntity;
    use FederationLib\Methods\Entities\SetRelationship;
    use FederationLib\Methods\Evidence\DeleteEvidence;
    use FederationLib\Methods\Evidence\GetEvidenceAttachments;
    use FederationLib\Methods\Evidence\GetEvidenceRecord;
    use FederationLib\Methods\Evidence\ListEvidence;
    use FederationLib\Methods\Evidence\SubmitEvidence;
    use FederationLib\Methods\Evidence\UpdateConfidentiality;
    use FederationLib\Methods\Evidence\UpdateTag;
    use FederationLib\Methods\GetServerInformation;
    use FederationLib\Methods\GetSpecification;
    use FederationLib\Methods\Operators\CreateOperator;
    use FederationLib\Methods\Operators\DeleteOperator;
    use FederationLib\Methods\Operators\DisableOperator;
    use FederationLib\Methods\Operators\EnableOperator;
    use FederationLib\Methods\Operators\GetOperator;
    use FederationLib\Methods\Operators\GetSelfOperator;
    use FederationLib\Methods\Operators\ListAssignedOperatorReports;
    use FederationLib\Methods\Operators\ListOperatorAuditLogs;
    use FederationLib\Methods\Operators\ListOperatorBlacklist;
    use FederationLib\Methods\Operators\ListOperatorEvidence;
    use FederationLib\Methods\Operators\ListOperatorReports;
    use FederationLib\Methods\Operators\ListOperators;
    use FederationLib\Methods\Operators\ManageClientPermissions;
    use FederationLib\Methods\Operators\ManageManagementPermissions;
    use FederationLib\Methods\Operators\ManageOperatorPermissions;
    use FederationLib\Methods\Operators\GenerateOperatorAccessToken;
    use FederationLib\Methods\Reports\AddEvidence;
    use FederationLib\Methods\Reports\CloseReport;
    use FederationLib\Methods\Reports\DeleteReport;
    use FederationLib\Methods\Reports\GetReport;
    use FederationLib\Methods\Reports\ListReports;
    use FederationLib\Methods\Reports\AssignOperator;
    use FederationLib\Methods\Reports\SubmitReport;
    use FederationLib\Methods\ScanContent;

    enum Method
    {
        case GET_SERVER_INFORMATION;

        case SCAN_CONTENT;

        case GET_SPECIFICATION;

        case LIST_AUDIT_LOGS;
        case VIEW_AUDIT_ENTRY;

        case LIST_OPERATORS;
        case CREATE_OPERATOR;
        case GET_SELF_OPERATOR;
        case DELETE_OPERATOR;
        case ENABLE_OPERATOR;
        case DISABLE_OPERATOR;
        case GET_OPERATOR;
        case GENERATE_OPERATOR_ACCESS_TOKEN;
        case MANAGE_OPERATOR_PERMISSIONS;
        case MANAGE_MANAGEMENT_PERMISSIONS;
        case MANAGE_CLIENT_PERMISSIONS;
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
        case CLEAR_REPUTATION;
        case SET_ENTITY_RELATIONSHIP;
        case CLEAR_ENTITY_RELATIONSHIP;

        case LIST_EVIDENCE;
        case SUBMIT_EVIDENCE;
        case GET_EVIDENCE_RECORD;
        case GET_EVIDENCE_ATTACHMENTS;
        case UPDATE_CONFIDENTIALITY;
        case UPDATE_EVIDENCE_TAG;
        case DELETE_EVIDENCE;

        case LIST_BLACKLIST;
        case BLACKLIST_ENTITY;
        case DELETE_BLACKLIST;
        case LIFT_BLACKLIST;
        case GET_BLACKLIST_RECORD;

        case SUBMIT_REPORT;
        case LIST_REPORTS;
        case LIST_OPERATOR_REPORTS;
        case LIST_ENTITY_REPORTS;
        case LIST_ASSIGNED_OPERATOR_REPORTS;
        case GET_REPORT;
        case CLOSE_REPORT;
        case DELETE_REPORT;
        case ASSIGN_OPERATOR_TO_REPORT;
        case ADD_EVIDENCE_TO_REPORT;

        case UPLOAD_ATTACHMENT;
        case DOWNLOAD_ATTACHMENT;
        case GET_ATTACHMENT_INFO;
        case DELETE_ATTACHMENT;
        case LIST_ATTACHMENTS;

        /**
         * Handles the request of the method
         *
         * @throws RequestException Thrown if there was an error while executing the request method
         */
        public function handleRequest(): void
        {
            [, , $handlerClass] = $this->getRouteInfo();
            /** @var RequestHandler $handlerClass */
            $handlerClass::handleRequest();
        }

        /**
         * Returns the route information for this method case.
         *
         * @return array{string, string, string} Tuple of [path, httpMethod, handlerClass]
         */
        public function getRouteInfo(): array
        {
            return match($this)
            {
                self::GET_SERVER_INFORMATION => ['/info', 'get', GetServerInformation::class],
                self::SCAN_CONTENT => ['/scan', 'post', ScanContent::class],
                self::GET_SPECIFICATION => ['/specification', 'get', GetSpecification::class],
                self::VIEW_AUDIT_ENTRY => ['/audit/{uuid}', 'get', ViewAuditEntry::class],
                self::LIST_AUDIT_LOGS => ['/', 'get', ListAuditLogs::class],
                self::DOWNLOAD_ATTACHMENT => ['/attachments/{uuid}', 'get', DownloadAttachment::class],
                self::DELETE_ATTACHMENT => ['/attachments/{uuid}', 'delete', DeleteAttachment::class],
                self::GET_ATTACHMENT_INFO => ['/attachments/{uuid}/info', 'get', GetAttachmentInfo::class],
                self::LIST_ATTACHMENTS => ['/attachments', 'get', ListAttachments::class],
                self::UPLOAD_ATTACHMENT => ['/attachments', 'post', UploadAttachment::class],
                self::GET_ENTITY_RECORD => ['/entities/{identifier}', 'get', GetEntityRecord::class],
                self::DELETE_ENTITY => ['/entities/{identifier}', 'delete', DeleteEntity::class],
                self::SET_ENTITY_RELATIONSHIP => ['/entities/{identifier}/relationship', 'patch', SetRelationship::class],
                self::CLEAR_ENTITY_RELATIONSHIP => ['/entities/{identifier}/relationship', 'delete', ClearRelationship::class],
                self::LIST_ENTITY_EVIDENCE => ['/entities/{identifier}/evidence', 'get', ListEntityEvidence::class],
                self::LIST_ENTITY_AUDIT_LOGS => ['/entities/{identifier}/audit', 'get', ListEntityAuditLogs::class],
                self::LIST_ENTITY_BLACKLIST_RECORDS => ['/entities/{identifier}/blacklist', 'get', ListEntityBlacklistRecords::class],
                self::CLEAR_REPUTATION => ['/entities/{identifier}/clearReputation', 'patch', ClearReputation::class],
                self::LIST_ENTITIES => ['/entities', 'get', ListEntities::class],
                self::PUSH_ENTITY => ['/entities', 'post', PushEntity::class],
                self::LIST_BLACKLIST => ['/blacklist', 'get', ListBlacklist::class],
                self::BLACKLIST_ENTITY => ['/blacklist', 'post', BlacklistEntity::class],
                self::GET_BLACKLIST_RECORD => ['/blacklist/{uuid}', 'get', GetBlacklistRecord::class],
                self::DELETE_BLACKLIST => ['/blacklist/{uuid}', 'delete', DeleteBlacklist::class],
                self::LIFT_BLACKLIST => ['/blacklist/{uuid}/lift', 'patch', LiftBlacklist::class],
                self::LIST_EVIDENCE => ['/evidence', 'get', ListEvidence::class],
                self::SUBMIT_EVIDENCE => ['/evidence', 'post', SubmitEvidence::class],
                self::GET_EVIDENCE_RECORD => ['/evidence/{uuid}', 'get', GetEvidenceRecord::class],
                self::DELETE_EVIDENCE => ['/evidence/{uuid}', 'delete', DeleteEvidence::class],
                self::GET_EVIDENCE_ATTACHMENTS => ['/evidence/{uuid}/attachments', 'get', GetEvidenceAttachments::class],
                self::UPDATE_CONFIDENTIALITY => ['/evidence/{uuid}/update_confidentiality', 'patch', UpdateConfidentiality::class],
                self::UPDATE_EVIDENCE_TAG => ['/evidence/{uuid}/update_tag', 'patch', UpdateTag::class],
                self::SUBMIT_REPORT => ['/reports', 'post', SubmitReport::class],
                self::LIST_REPORTS => ['/reports', 'get', ListReports::class],
                self::GET_REPORT => ['/reports/{uuid}', 'get', GetReport::class],
                self::CLOSE_REPORT => ['/reports/{uuid}/close', 'patch', CloseReport::class],
                self::DELETE_REPORT => ['/reports/{uuid}', 'delete', DeleteReport::class],
                self::LIST_OPERATOR_REPORTS => ['/operators/{uuid}/reports', 'get', ListOperatorReports::class],
                self::LIST_ENTITY_REPORTS => ['/entities/{identifier}/reports', 'get', ListEntityReports::class],
                self::LIST_ASSIGNED_OPERATOR_REPORTS => ['/operators/{uuid}/reports/assigned', 'get', ListAssignedOperatorReports::class],
                self::ASSIGN_OPERATOR_TO_REPORT => ['/reports/{uuid}/assign', 'patch', AssignOperator::class],
                self::ADD_EVIDENCE_TO_REPORT => ['/evidence/{uuid}/link_report', 'patch', AddEvidence::class],
                self::LIST_OPERATORS => ['/operators', 'get', ListOperators::class],
                self::CREATE_OPERATOR => ['/operators', 'post', CreateOperator::class],
                self::GET_SELF_OPERATOR => ['/operators/self', 'get', GetSelfOperator::class],
                self::GENERATE_OPERATOR_ACCESS_TOKEN => ['/operators/refresh', 'post', GenerateOperatorAccessToken::class],
                self::GET_OPERATOR => ['/operators/{uuid}', 'get', GetOperator::class],
                self::DELETE_OPERATOR => ['/operators/{uuid}', 'delete', DeleteOperator::class],
                self::ENABLE_OPERATOR => ['/operators/{uuid}/enable', 'patch', EnableOperator::class],
                self::DISABLE_OPERATOR => ['/operators/{uuid}/disable', 'patch', DisableOperator::class],
                self::MANAGE_OPERATOR_PERMISSIONS => ['/operators/{uuid}/operator_permissions', 'patch', ManageOperatorPermissions::class],
                self::MANAGE_MANAGEMENT_PERMISSIONS => ['/operators/{uuid}/management_permissions', 'patch', ManageManagementPermissions::class],
                self::MANAGE_CLIENT_PERMISSIONS => ['/operators/{uuid}/client_permissions', 'patch', ManageClientPermissions::class],
                self::LIST_OPERATOR_EVIDENCE => ['/operators/{uuid}/evidence', 'get', ListOperatorEvidence::class],
                self::LIST_OPERATOR_AUDIT_LOGS => ['/operators/{uuid}/audit', 'get', ListOperatorAuditLogs::class],
                self::LIST_OPERATOR_BLACKLIST => ['/operators/{uuid}/blacklist', 'get', ListOperatorBlacklist::class],
            };
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
                // Server methods
                $path === '/' && $requestMethod === 'GET' => Method::LIST_AUDIT_LOGS,
                $path === '/info' && $requestMethod === 'GET' => Method::GET_SERVER_INFORMATION,
                $path === '/specification' && $requestMethod === 'GET' => Method::GET_SPECIFICATION,
                $path === '/specification.json' && $requestMethod === 'GET' => Method::GET_SPECIFICATION,
                $path === '/scan' && $requestMethod === 'POST' => Method::SCAN_CONTENT,
                preg_match('#^/audit/([a-fA-F0-9\-]{36})$#', $path) && $requestMethod === 'GET' => Method::VIEW_AUDIT_ENTRY,

                // Attachment methods
                preg_match('#^/attachments/([a-fA-F0-9\-]{36})$#', $path) && $requestMethod === 'GET' => Method::DOWNLOAD_ATTACHMENT,
                preg_match('#^/attachments/([a-fA-F0-9\-]{36})$#', $path) && $requestMethod === 'DELETE' => Method::DELETE_ATTACHMENT,
                preg_match('#^/attachments/([a-fA-F0-9\-]{36})/info$#', $path) && $requestMethod === 'GET' => Method::GET_ATTACHMENT_INFO,
                $path === '/attachments' && $requestMethod === 'GET' => Method::LIST_ATTACHMENTS,
                $path === '/attachments' && ($requestMethod === 'POST' || $requestMethod === 'PUT')  => Method::UPLOAD_ATTACHMENT,

                // Entities methods
                // UUID entity relationship routing
                preg_match('#^/entities/([a-fA-F0-9\-]{36})/relationship$#', $path) && $requestMethod === 'PATCH' => Method::SET_ENTITY_RELATIONSHIP,
                preg_match('#^/entities/([a-fA-F0-9\-]{36})/relationship$#', $path) && $requestMethod === 'DELETE' => Method::CLEAR_ENTITY_RELATIONSHIP,
                // SHA-256 entity relationship routing
                preg_match('#^/entities/([a-f0-9\-]{64})/relationship$#', $path) && $requestMethod === 'PATCH' => Method::SET_ENTITY_RELATIONSHIP,
                preg_match('#^/entities/([a-f0-9\-]{64})/relationship$#', $path) && $requestMethod === 'DELETE' => Method::CLEAR_ENTITY_RELATIONSHIP,
                // Entity address relationship routing
                preg_match('#^/entities/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/relationship$#', $path) && $requestMethod === 'PATCH' => Method::SET_ENTITY_RELATIONSHIP,
                preg_match('#^/entities/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/relationship$#', $path) && $requestMethod === 'DELETE' => Method::CLEAR_ENTITY_RELATIONSHIP,
                // UUID entity routing
                $path === '/entities' && $requestMethod === 'GET' => Method::LIST_ENTITIES,
                $path === '/entities' && $requestMethod === 'POST' => Method::PUSH_ENTITY,
                preg_match('#^/entities/([a-fA-F0-9\-]{36})$#', $path) && $requestMethod === 'GET' => Method::GET_ENTITY_RECORD,
                preg_match('#^/entities/([a-fA-F0-9\-]{36})$#', $path) && $requestMethod === 'DELETE' => Method::DELETE_ENTITY,
                preg_match('#^/entities/([a-fA-F0-9\-]{36})/evidence$#', $path) && $requestMethod === 'GET' => Method::LIST_ENTITY_EVIDENCE,
                preg_match('#^/entities/([a-fA-F0-9\-]{36})/audit$#', $path) && $requestMethod === 'GET' => Method::LIST_ENTITY_AUDIT_LOGS,
                preg_match('#^/entities/([a-fA-F0-9\-]{36})/blacklist$#', $path) && $requestMethod === 'GET' => Method::LIST_ENTITY_BLACKLIST_RECORDS,
                preg_match('#^/entities/([a-fA-F0-9\-]{36})/clearReputation$#', $path) && $requestMethod === 'PATCH' => Method::CLEAR_REPUTATION,
                preg_match('#^/entities/([a-fA-F0-9\-]{36})/reports$#', $path) && $requestMethod === 'GET' => Method::LIST_ENTITY_REPORTS,
                // SHA-256 entity routing
                preg_match('#^/entities/([a-f0-9\-]{64})$#', $path) && $requestMethod === 'GET' => Method::GET_ENTITY_RECORD,
                preg_match('#^/entities/([a-f0-9\-]{64})$#', $path) && $requestMethod === 'DELETE' => Method::DELETE_ENTITY,
                preg_match('#^/entities/([a-f0-9\-]{64})/evidence$#', $path) && $requestMethod === 'GET' => Method::LIST_ENTITY_EVIDENCE,
                preg_match('#^/entities/([a-f0-9\-]{64})/audit$#', $path) && $requestMethod === 'GET' => Method::LIST_ENTITY_AUDIT_LOGS,
                preg_match('#^/entities/([a-f0-9\-]{64})/blacklist$#', $path) && $requestMethod === 'GET' => Method::LIST_ENTITY_BLACKLIST_RECORDS,
                preg_match('#^/entities/([a-f0-9\-]{64})/clearReputation$#', $path) && $requestMethod === 'PATCH' => Method::CLEAR_REPUTATION,
                preg_match('#^/entities/([a-f0-9\-]{64})/reports$#', $path) && $requestMethod === 'GET' => Method::LIST_ENTITY_REPORTS,
                // Entity address routing
                preg_match('#^/entities/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})$#', $path) && $requestMethod === 'GET' => Method::GET_ENTITY_RECORD,
                preg_match('#^/entities/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})$#', $path) && $requestMethod === 'DELETE' => Method::DELETE_ENTITY,
                preg_match('#^/entities/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/evidence$#', $path) && $requestMethod === 'GET' => Method::LIST_ENTITY_EVIDENCE,
                preg_match('#^/entities/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/audit$#', $path) && $requestMethod === 'GET' => Method::LIST_ENTITY_AUDIT_LOGS,
                preg_match('#^/entities/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/blacklist$#', $path) && $requestMethod === 'GET' => Method::LIST_ENTITY_BLACKLIST_RECORDS,
                preg_match('#^/entities/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/clearReputation$#', $path) && $requestMethod === 'PATCH' => Method::CLEAR_REPUTATION,
                preg_match('#^/entities/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/reports$#', $path) && $requestMethod === 'GET' => Method::LIST_ENTITY_REPORTS,

                // Blcaklist Methods
                $path === '/blacklist' && $requestMethod === 'GET' => Method::LIST_BLACKLIST,
                $path === '/blacklist' && $requestMethod === 'POST' => Method::BLACKLIST_ENTITY,
                preg_match('#^/blacklist/([a-fA-F0-9\-]{36})$#', $path) && $requestMethod === 'GET' => Method::GET_BLACKLIST_RECORD,
                preg_match('#^/blacklist/([a-fA-F0-9\-]{36})$#', $path) && $requestMethod === 'DELETE' => Method::DELETE_BLACKLIST,
                preg_match('#^/blacklist/([a-fA-F0-9\-]{36})/lift$#', $path) && $requestMethod === 'PATCH' => Method::LIFT_BLACKLIST,

                // Evidence Methods
                $path === '/evidence' && $requestMethod === 'GET' => Method::LIST_EVIDENCE,
                $path === '/evidence' && $requestMethod === 'POST' => Method::SUBMIT_EVIDENCE,
                preg_match('#^/evidence/([a-fA-F0-9\-]{36})$#', $path) && $requestMethod === 'GET' => Method::GET_EVIDENCE_RECORD,
                preg_match('#^/evidence/([a-fA-F0-9\-]{36})/attachments$#', $path) && $requestMethod === 'GET' => Method::GET_EVIDENCE_ATTACHMENTS,
                preg_match('#^/evidence/([a-fA-F0-9\-]{36})/update_confidentiality$#', $path) && $requestMethod === 'PATCH' => Method::UPDATE_CONFIDENTIALITY,
                preg_match('#^/evidence/([a-fA-F0-9\-]{36})/update_tag$#', $path) && $requestMethod === 'PATCH' => Method::UPDATE_EVIDENCE_TAG,
                preg_match('#^/evidence/([a-fA-F0-9\-]{36})/link_report$#', $path) && $requestMethod === 'PATCH' => Method::ADD_EVIDENCE_TO_REPORT,
                preg_match('#^/evidence/([a-fA-F0-9\-]{36})$#', $path) && $requestMethod === 'DELETE' => Method::DELETE_EVIDENCE,

                // Operator Methods
                $path === '/operators' && $requestMethod === 'GET' => Method::LIST_OPERATORS,
                $path === '/operators' && $requestMethod === 'POST' => Method::CREATE_OPERATOR,
                $path === '/operators/self' && $requestMethod === 'GET' => Method::GET_SELF_OPERATOR,
                $path === '/operators/refresh' && $requestMethod === 'POST' => Method::GENERATE_OPERATOR_ACCESS_TOKEN,
                preg_match('#^/operators/([a-fA-F0-9\-]{36})$#', $path) && $requestMethod === 'GET' => Method::GET_OPERATOR,
                preg_match('#^/operators/([a-fA-F0-9\-]{36})$#', $path) && $requestMethod === 'DELETE' => Method::DELETE_OPERATOR,
                preg_match('#^/operators/([a-fA-F0-9\-]{36})/enable$#', $path) && $requestMethod === 'PATCH' => Method::ENABLE_OPERATOR,
                preg_match('#^/operators/([a-fA-F0-9\-]{36})/disable$#', $path) && $requestMethod === 'PATCH' => Method::DISABLE_OPERATOR,
                preg_match('#^/operators/([a-fA-F0-9\-]{36})/refresh$#', $path) && $requestMethod === 'POST' => Method::GENERATE_OPERATOR_ACCESS_TOKEN,
                preg_match('#^/operators/([a-fA-F0-9\-]{36})/operator_permissions$#', $path) && $requestMethod === 'PATCH' => Method::MANAGE_OPERATOR_PERMISSIONS,
                preg_match('#^/operators/([a-fA-F0-9\-]{36})/management_permissions$#', $path) && $requestMethod === 'PATCH' => Method::MANAGE_MANAGEMENT_PERMISSIONS,
                preg_match('#^/operators/([a-fA-F0-9\-]{36})/client_permissions$#', $path) && $requestMethod === 'PATCH' => Method::MANAGE_CLIENT_PERMISSIONS,
                preg_match('#^/operators/([a-fA-F0-9\-]{36})/evidence$#', $path) && $requestMethod === 'GET' => Method::LIST_OPERATOR_EVIDENCE,
                preg_match('#^/operators/([a-fA-F0-9\-]{36})/audit$#', $path) && $requestMethod === 'GET' => Method::LIST_OPERATOR_AUDIT_LOGS,
                preg_match('#^/operators/([a-fA-F0-9\-]{36})/blacklist$#', $path) && $requestMethod === 'GET' => Method::LIST_OPERATOR_BLACKLIST,
                preg_match('#^/operators/([a-fA-F0-9\-]{36})/reports/assigned$#', $path) && $requestMethod === 'GET' => Method::LIST_ASSIGNED_OPERATOR_REPORTS,
                preg_match('#^/operators/([a-fA-F0-9\-]{36})/reports$#', $path) && $requestMethod === 'GET' => Method::LIST_OPERATOR_REPORTS,

                // Reports methods
                $path === '/reports' && $requestMethod === 'GET' => Method::LIST_REPORTS,
                $path === '/reports' && $requestMethod === 'POST' => Method::SUBMIT_REPORT,
                preg_match('#^/reports/([a-fA-F0-9\-]{36})/close$#', $path) && $requestMethod === 'PATCH' => Method::CLOSE_REPORT,
                preg_match('#^/reports/([a-fA-F0-9\-]{36})/assign$#', $path) && $requestMethod === 'PATCH' => Method::ASSIGN_OPERATOR_TO_REPORT,
                preg_match('#^/reports/([a-fA-F0-9\-]{36})$#', $path) && $requestMethod === 'GET' => Method::GET_REPORT,
                preg_match('#^/reports/([a-fA-F0-9\-]{36})$#', $path) && $requestMethod === 'DELETE' => Method::DELETE_REPORT,
                default => null,
            };

        }
    }
