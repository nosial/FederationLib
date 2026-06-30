<?php

    namespace FederationLib\Classes;

    use FederationLib\Enums\Method;
    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use FederationLib\Objects\AuditLog;
    use FederationLib\Objects\BlacklistRecord;
    use FederationLib\Objects\EntityRecord;
    use FederationLib\Objects\ErrorResponse;
    use FederationLib\Objects\EvidenceRecord;
    use FederationLib\Objects\FileAttachmentRecord;
    use FederationLib\Objects\OperatorRecord;
    use FederationLib\Objects\ReportRecord;
    use FederationLib\Objects\ReportSubmission;
    use FederationLib\Objects\ScannedContent;
    use FederationLib\Objects\ScannedContent\ContentClassification;
    use FederationLib\Objects\ScannedContent\ResolvedEntity;
    use FederationLib\Objects\ScannedContent\ResolvedEntityPosition;
    use FederationLib\Objects\ServerInformation;
    use FederationLib\Objects\SuccessResponse;
    use FederationLib\Objects\UploadResult;

    class SpecificationGenerator
    {
        /**
         * Generate the complete OpenAPI specification array.
         *
         * @return array The complete OpenAPI specification.
         */
        public static function generate(): array
        {
            return [
                'openapi' => '3.2.0',
                'info' => self::getInfo(),
                'jsonSchemaDialect' => 'https://spec.openapis.org/oas/3.1/dialect/base',
                'servers' => self::getServers(),
                'tags' => self::getTags(),
                'paths' => self::getPaths(),
                'components' => [
                    'schemas' => self::getSchemas(),
                    'securitySchemes' => self::getSecuritySchemes(),
                ],
                'security' => self::getSecurity(),
            ];
        }

        /**
         * Get the API information section.
         *
         * @return array The API information.
         */
        private static function getInfo(): array
        {
            $serverConfig = Configuration::getServerConfiguration();
            return [
                'title' => $serverConfig->getName(),
                'summary' => 'Federation API for cross-server entity and report management',
                'description' => 'Federation API server for managing entities, evidence, blacklists, reports, and operators.',
                'version' => '2025.01',
            ];
        }

        /**
         * Get the servers section.
         *
         * @return array The servers configuration.
         */
        private static function getServers(): array
        {
            $serverConfig = Configuration::getServerConfiguration();
            return [
                [
                    'url' => $serverConfig->getBaseUrl(),
                    'description' => 'Federation API Server',
                    'name' => $serverConfig->getName(),
                ],
            ];
        }

        /**
         * Get the security schemes section.
         *
         * @return array The security schemes configuration.
         */
        private static function getSecuritySchemes(): array
        {
            return [
                'bearerAuth' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'description' => 'Bearer token authentication. Token must be 32 characters in length.',
                ],
            ];
        }

        /**
         * Get the tags section.
         *
         * @return array The API tags.
         */
        private static function getTags(): array
        {
            return [
                ['name' => 'Reports', 'description' => 'Report submission, retrieval, and management'],
                ['name' => 'Entities', 'description' => 'Entity records and relationships'],
                ['name' => 'Operators', 'description' => 'Operator accounts and permissions'],
                ['name' => 'Evidence', 'description' => 'Evidence submission and management'],
                ['name' => 'Blacklist', 'description' => 'Entity blacklisting operations'],
                ['name' => 'Attachments', 'description' => 'File attachment upload and download'],
                ['name' => 'Audit', 'description' => 'Audit log viewing'],
                ['name' => 'Server', 'description' => 'Server information and metadata'],
                ['name' => 'Specification', 'description' => 'OpenAPI specification endpoint'],
                ['name' => 'Scan', 'description' => 'Content scanning operations'],
            ];
        }

        /**
         * Get the global security section.
         *
         * @return array The global security requirements.
         */
        private static function getSecurity(): array
        {
            return [
                ['bearerAuth' => []],
            ];
        }

        /**
         * Get the component schemas from all object classes implementing ObjectSpecificationInterface.
         *
         * @return array The component schemas.
         */
        private static function getSchemas(): array
        {
            $objectClasses = [
                'AuditLog' => AuditLog::class,
                'BlacklistRecord' => BlacklistRecord::class,
                'ContentClassification' => ContentClassification::class,
                'EntityRecord' => EntityRecord::class,
                'ErrorResponse' => ErrorResponse::class,
                'EvidenceRecord' => EvidenceRecord::class,
                'FileAttachmentRecord' => FileAttachmentRecord::class,
                'OperatorRecord' => OperatorRecord::class,
                'ReportRecord' => ReportRecord::class,
                'ReportSubmission' => ReportSubmission::class,
                'ResolvedEntity' => ResolvedEntity::class,
                'ResolvedEntityPosition' => ResolvedEntityPosition::class,
                'ScannedContent' => ScannedContent::class,
                'ServerInformation' => ServerInformation::class,
                'SuccessResponse' => SuccessResponse::class,
                'UploadResult' => UploadResult::class,
            ];

            $schemas = [];
            foreach($objectClasses as $name => $className)
            {
                if(is_subclass_of($className, ObjectSpecificationInterface::class))
                {
                    $schemas[$name] = [
                        'type' => $className::getObjectType(),
                        'properties' => $className::getObjectProperties(),
                        'required' => $className::getObjectRequired(),
                    ];
                }
                else
                {
                    $schemas[$name] = ['type' => 'object'];
                }
            }

            return $schemas;
        }

        /**
         * Get the paths section built from all available methods.
         *
         * @return array The API paths.
         */
        private static function getPaths(): array
        {
            $paths = [];

            $handlerMap = self::getMethodRouteMap();
            foreach(Method::cases() as $method)
            {
                if(!isset($handlerMap[$method->name]))
                {
                    continue;
                }

                [$path, $httpMethod, $handlerClass] = $handlerMap[$method->name];

                if(!is_subclass_of($handlerClass, RequestSpecificationInterface::class))
                {
                    continue;
                }

                $responses = $handlerClass::getResponses();
                foreach($responses as $code => &$response)
                {
                    if(!isset($response['summary']) && isset($response['description']))
                    {
                        $response['summary'] = str_contains($response['description'], '.')
                            ? substr($response['description'], 0, strpos($response['description'], '.'))
                            : $response['description'];
                    }
                }
                unset($response);

                $operation = [
                    'tags' => $handlerClass::getTags(),
                    'summary' => $handlerClass::getSummary(),
                    'description' => $handlerClass::getDescription(),
                    'operationId' => $handlerClass::getOperationId(),
                    'parameters' => $handlerClass::getParameters(),
                    'responses' => $responses,
                ];

                $requestBody = $handlerClass::getRequestBody();
                if($requestBody !== null)
                {
                    $operation['requestBody'] = $requestBody;
                }

                if(!isset($paths[$path]))
                {
                    $paths[$path] = [];
                }

                $paths[$path][$httpMethod] = $operation;
            }

            ksort($paths);
            return $paths;
        }

        /**
         * Get the route map for all available methods.
         *
         * @return array<string, array{string, string, string}>
         */
        private static function getMethodRouteMap(): array
        {
            $map = [];
            foreach(Method::cases() as $method)
            {
                $map[$method->name] = $method->getRouteInfo();
            }
            return $map;
        }
    }
