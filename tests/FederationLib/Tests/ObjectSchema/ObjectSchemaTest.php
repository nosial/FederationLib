<?php

    namespace FederationLib\Tests\ObjectSchema;

    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use FederationLib\Interfaces\SerializableInterface;
    use FederationLib\Interfaces\StandardObjectInterface;
    use PHPUnit\Framework\TestCase;

    class ObjectSchemaTest extends TestCase
    {
        private static function getSchemaClasses(): array
        {
            return [
                'AuditLog' => 'FederationLib\Objects\AuditLog',
                'BlacklistRecord' => 'FederationLib\Objects\BlacklistRecord',
                'ContentClassification' => 'FederationLib\Objects\ScannedContent\ContentClassification',
                'ContentInput' => 'FederationLib\Objects\ContentInput',
                'EntityRecord' => 'FederationLib\Objects\EntityRecord',
                'EntityQueryResult' => 'FederationLib\Objects\EntityQueryResult',
                'ErrorResponse' => 'FederationLib\Objects\ErrorResponse',
                'EvidenceRecord' => 'FederationLib\Objects\EvidenceRecord',
                'FileAttachmentRecord' => 'FederationLib\Objects\FileAttachmentRecord',
                'OperatorCreated' => 'FederationLib\Objects\OperatorCreated',
                'OperatorRecord' => 'FederationLib\Objects\OperatorRecord',
                'ReportRecord' => 'FederationLib\Objects\ReportRecord',
                'ReportSubmission' => 'FederationLib\Objects\ReportSubmission',
                'ResolvedEntity' => 'FederationLib\Objects\ScannedContent\ResolvedEntity',
                'ResolvedEntityPosition' => 'FederationLib\Objects\ScannedContent\ResolvedEntityPosition',
                'ScannedContent' => 'FederationLib\Objects\ScannedContent',
                'SearchResult' => 'FederationLib\Objects\SearchResult',
                'ServerInformation' => 'FederationLib\Objects\ServerInformation',
                'SuccessResponse' => 'FederationLib\Objects\SuccessResponse',
                'UploadResult' => 'FederationLib\Objects\UploadResult',
            ];
        }

        public function testAllSchemasHaveObjectType(): void
        {
            /** @var ObjectSpecificationInterface $className */
            foreach (self::getSchemaClasses() as $name => $className)
            {
                $this->assertTrue(
                    is_subclass_of($className, ObjectSpecificationInterface::class),
                    "$className does not implement ObjectSpecificationInterface"
                );
                $this->assertEquals('object', $className::getObjectType(), "$name type should be 'object'");
            }
        }

        public function testAllRequiredFieldsExistInProperties(): void
        {
            /** @var ObjectSpecificationInterface $className */
            foreach (self::getSchemaClasses() as $name => $className)
            {
                $properties = $className::getObjectProperties();
                $required = $className::getObjectRequired();

                foreach ($required as $field)
                {
                    $this->assertArrayHasKey(
                        $field,
                        $properties,
                        "Required field '$field' not found in getObjectProperties() of $name"
                    );
                }
            }
        }

        public function testAllReferencesAreValid(): void
        {
            /** @var ObjectSpecificationInterface $className */
            foreach (self::getSchemaClasses() as $name => $className)
            {
                $reference = $className::getReference();
                $this->assertIsString($reference, "$name reference should be a string");
                $this->assertNotEmpty($reference, "$name reference should not be empty");

                $expectedPrefix = '#/components/schemas/';
                $this->assertStringStartsWith(
                    $expectedPrefix,
                    $reference,
                    "$name reference '$reference' should start with '$expectedPrefix'"
                );

                $schemaName = substr($reference, strlen($expectedPrefix));
                $this->assertNotEmpty($schemaName, "$name should have a non-empty schema name after prefix");
            }
        }

        public function testAllPropertyDefinitionsAreWellFormed(): void
        {
            /** @var ObjectSpecificationInterface $className */
            foreach (self::getSchemaClasses() as $name => $className)
            {
                $properties = $className::getObjectProperties();

                foreach ($properties as $propName => $definition)
                {
                    $this->assertIsArray($definition, "Property '$propName' in $name must have an array definition");

                    $this->assertArrayNotHasKey('nullable', $definition, "Property '$propName' in $name uses 'nullable', which is not valid in OpenAPI 3.1 and later");

                    if (isset($definition['$ref']))
                    {
                        $this->assertIsString($definition['$ref']);
                        $this->assertStringStartsWith('#/components/schemas/', $definition['$ref']);
                    }
                    elseif (isset($definition['anyOf']) || isset($definition['oneOf']))
                    {
                        $subschemas = $definition['anyOf'] ?? $definition['oneOf'];
                        $this->assertIsArray($subschemas);
                        $this->assertNotEmpty($subschemas, "Property '$propName' in $name must have at least one subschema");

                        foreach ($subschemas as $subschema)
                        {
                            $this->assertTrue(isset($subschema['$ref']) || isset($subschema['type']), "Subschema of '$propName' in $name must have a '\$ref' or 'type' key");
                        }
                    }
                    else
                    {
                        $this->assertArrayHasKey('type', $definition, "Property '$propName' in $name must have a 'type' key when not using \$ref, anyOf or oneOf");

                        // OpenAPI 3.1 and later express nullable members as a type array, e.g. ['string', 'null']
                        $types = (array)$definition['type'];
                        $this->assertNotEmpty($types, "Property '$propName' in $name must declare at least one type");
                        foreach ($types as $type)
                        {
                            $this->assertContains($type, ['string', 'integer', 'number', 'boolean', 'object', 'array', 'null'], "Property '$propName' in $name has an invalid type");
                        }

                        if (in_array('array', $types, true))
                        {
                            $this->assertArrayHasKey('items', $definition, "Array property '$propName' in $name must have 'items' key");
                        }

                        if (isset($definition['enum']) && in_array('null', $types, true))
                        {
                            $this->assertContains(null, $definition['enum'], "Nullable enum property '$propName' in $name must include null in its enum");
                        }
                    }
                }
            }
        }

        /**
         * Returns every schema reference within a property definition, including those nested in anyOf, oneOf and items
         *
         * @param array $definition The property definition
         * @return string[] The schema references
         */
        private static function getDefinitionReferences(array $definition): array
        {
            $references = isset($definition['$ref']) ? [$definition['$ref']] : [];
            foreach (array_merge($definition['anyOf'] ?? [], $definition['oneOf'] ?? [], isset($definition['items']) ? [$definition['items']] : []) as $subschema)
            {
                $references = array_merge($references, self::getDefinitionReferences($subschema));
            }

            return $references;
        }

        public function testAllObjectReferencesResolveToKnownSchemas(): void
        {
            $allSchemas = array_keys(self::getSchemaClasses());

            /** @var ObjectSpecificationInterface $className */
            foreach (self::getSchemaClasses() as $name => $className)
            {
                $properties = $className::getObjectProperties();

                foreach ($properties as $propName => $definition)
                {
                    foreach (self::getDefinitionReferences($definition) as $ref)
                    {
                        $schemaName = str_replace('#/components/schemas/', '', $ref);
                        $this->assertContains(
                            $schemaName,
                            $allSchemas,
                            "Reference '$ref' in '$name::$propName' points to unknown schema '$schemaName'"
                        );
                    }
                }
            }
        }

        public function testSerializableSchemasHaveToArray(): void
        {
            foreach (self::getSchemaClasses() as $name => $className)
            {
                $hasSerializable = is_subclass_of($className, SerializableInterface::class);
                $hasToArray = method_exists($className, 'toArray');
                $hasFromArray = method_exists($className, 'fromArray');

                if ($hasSerializable)
                {
                    $this->assertTrue($hasToArray, "$name implements SerializableInterface but missing toArray()");
                    $this->assertTrue($hasFromArray, "$name implements SerializableInterface but missing fromArray()");
                }
            }
        }

        public function testToArrayKeysMatchPropertyDefinitions(): void
        {
            $tests = [
                'AuditLog' => ['uuid' => 'a', 'type' => 'OTHER', 'message' => 'test', 'timestamp' => 1000],
                'BlacklistRecord' => ['uuid' => 'a', 'operator' => 'b', 'entity' => 'c', 'type' => 'OTHER', 'created' => 1000],
                'EntityRecord' => ['uuid' => 'a', 'host' => 'example.com'],
                'EvidenceRecord' => ['uuid' => 'a', 'entity' => 'b', 'operator' => 'c', 'created' => 1000],
                'FileAttachmentRecord' => ['uuid' => 'a', 'evidence' => 'b', 'file_name' => 'f.txt', 'file_size' => 100, 'file_mime' => 'text/plain', 'created' => 1000],
                'OperatorRecord' => ['uuid' => 'a', 'name' => 'op', 'created' => 1000, 'updated' => 1000],
                'ReportRecord' => ['uuid' => 'a', 'submitting_operator' => 'b', 'incident_type' => 'OTHER', 'created' => 1000],
                'SearchResult' => ['type' => 'AUDIT_LOG', 'record' => ['uuid' => 'a', 'type' => 'OTHER', 'message' => 'test', 'timestamp' => 1000]],
                'ServerInformation' => ['server_name' => 'test'],
            ];

            foreach ($tests as $name => $constructArgs)
            {
                /** @var ObjectSpecificationInterface $className */
                $className = self::getSchemaClasses()[$name];

                if (!is_subclass_of($className, SerializableInterface::class))
                {
                    continue;
                }

                // The API responds with the standard representation when available, toArray() may carry internal members
                $instance = $className::fromArray($constructArgs);
                $toArrayKeys = array_keys($instance instanceof StandardObjectInterface ? $instance->toStandardArray() : $instance->toArray());
                $properties = $className::getObjectProperties();

                foreach ($toArrayKeys as $key)
                {
                    $this->assertArrayHasKey(
                        $key,
                        $properties,
                        "toArray() key '$key' not found in getObjectProperties() of $name"
                    );
                }
            }
        }
    }
