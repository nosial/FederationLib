<?php

    namespace FederationLib\Enums;

    enum ClassificationFlag : string
    {
        case MALICIOUS = 'malicious'; // red flag
        case SUSPICIOUS = 'suspicious'; // yellow flag
        case NORMAL = 'normal'; // green flag
    }
