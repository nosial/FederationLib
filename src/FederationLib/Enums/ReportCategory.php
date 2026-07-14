<?php

    namespace FederationLib\Enums;

    enum ReportCategory : string
    {
        case OPENED = 'OPENED';
        case CLOSED = 'CLOSED';
        case AUTOMATED = 'AUTOMATED';
        case UNASSIGNED = 'UNASSIGNED';
        case ASSIGNED = 'ASSIGNED';
    }
