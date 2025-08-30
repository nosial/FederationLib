<?php

    namespace FederationLib\Enums;

    enum NamedEntityType: string
    {
        case DOMAIN = 'domain';
        case URL = 'url';
        case EMAIL = 'email';
        case IPv4 = 'ipv4';
        case IPv6 = 'ipv6';
    }
