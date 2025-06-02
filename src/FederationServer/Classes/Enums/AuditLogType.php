<?php

    namespace FederationServer\Classes\Enums;

    enum AuditLogType : string
    {
        case OTHER = 'OTHER';
        case OPERATOR_CREATED = 'OPERATOR_CREATED';
        case OPERATOR_DELETED = 'OPERATOR_DELETED';

        case ATTACHMENT_UPLOADED = 'ATTACHMENT_UPLOADED';
    }
