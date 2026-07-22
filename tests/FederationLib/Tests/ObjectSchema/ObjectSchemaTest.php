<?php

    namespace FederationLib\Tests\ObjectSchema;

    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use FederationLib\Interfaces\SerializableInterface;
    use PHPUnit\Framework\TestCase;

    class ObjectSchemaTest extends TestCase
    {
        private static function getSchemaClasses(): array
        {
            return [
                'AuditLog' => 'FederationLib\Objects\AuditLog',
                'BlacklistRecord' => 'FederationLib\Objects\BlacklistRecord',
                'ContentClassification' => 'FederationLib\Objects\ScannedContent\ContentClassification',
                'EntityRecord' => 'FederationLib\Objects\EntityRecord',
                'ErrorResponse' => 'FederationLib\Objects\ErrorResponse',
                'EvidenceRecord' => 'FederationLib\Objects\EvidenceRecord',
                'FileAttachmentRecord' => 'FederationLib\Objects\FileAttachmentRecord',
                'OperatorRecord' => 'FederationLib\Objects\OperatorRecord',
                'ReportRecord' => 'FederationLib\Objects\ReportRecord',
                'ReportSubmission' => 'FederationLib\Objects\ReportSubmission',
                'ResolvedEntity' => 'FederationLib\Objects\ScannedContent\ResolvedEntity',
                'ResolvedEntityPosition' => 'FederationLib\Objects\ScannedContent\ResolvedEntityPosition',
                'ScannedContent' => 'FederationLib\Objects\ScannedContent',
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

                    if (isset($definition['$ref']))
                    {
                        $this->assertIsString($definition['$ref']);
                        $this->assertStringStartsWith('#/components/schemas/', $definition['$ref']);
                    }
                    else
                    {
                        $this->assertArrayHasKey('type', $definition, "Property '$propName' in $name must have a 'type' key when not using \$ref");
                        $this->assertIsString($definition['type']);

                        if ($definition['type'] === 'array')
                        {
                            $this->assertArrayHasKey('items', $definition, "Array property '$propName' in $name must have 'items' key");
                        }
                    }
                }
            }
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
                    if (isset($definition['$ref']))
                    {
                        $ref = $definition['$ref'];
                        $schemaName = str_replace('#/components/schemas/', '', $ref);
                        $this->assertContains(
                            $schemaName,
                            $allSchemas,
                            "Reference '$ref' in '$name::$propName' points to unknown schema '$schemaName'"
                        );
                    }

                    if (isset($definition['items']['$ref']))
                    {
                        $ref = $definition['items']['$ref'];
                        $schemaName = str_replace('#/components/schemas/', '', $ref);
                        $this->assertContains(
                            $schemaName,
                            $allSchemas,
                            "Reference '$ref' in '$name::$propName items' points to unknown schema '$schemaName'"
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

                $instance = $className::fromArray($constructArgs);
                $toArrayKeys = array_keys($instance->toArray());
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
