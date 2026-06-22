<?php

    namespace FederationLib\Enums;

    enum BayesianEventType : string
    {
        case TRAINING = 'training';
        case REJECTED = 'rejected';
        case CLASSIFICATION = 'classification';
        case UNKNOWN = 'unknown';
    }
