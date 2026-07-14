<?php

    namespace FederationLib\Enums;

    enum RecordType : string
    {
        case ENTITY = 'ENTITY';
        case EVIDENCE = 'EVIDENCE';
        case BLACKLIST = 'BLACKLIST';
        case REPORT = 'REPORT';
        case ATTACHMENT = 'ATTACHMENT';
        case AUDIT_LOG = 'AUDIT_LOG';
        case OPERATOR = 'OPERATOR';
    }
