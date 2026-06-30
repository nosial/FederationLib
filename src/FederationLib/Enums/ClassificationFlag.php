<?php

    namespace FederationLib\Enums;

    enum ClassificationFlag : string
    {
        case MALICIOUS = 'MALICIOUS'; // red flag
        case SUSPICIOUS = 'SUSPICIOUS'; // yellow flag
        case NORMAL = 'NORMAL'; // green flag
    }
