<?php

    namespace FederationLib\Tests\Operators;

    use FederationLib\Objects\OperatorRecord;
    use FederationLib\Objects\OperatorRecord\OperatorConfiguration;
    use FederationLib\Objects\OperatorRecord\OperatorPermissions;
    use PHPUnit\Framework\TestCase;

    /**
     * The OperatorRecord object of OFD 2.0: nested permissions and configuration, and the visibility of the
     * configuration to the operator itself and to operators with operator permissions only
     */
    class OperatorRecordTest extends TestCase
    {
        private function createRecord(string $uuid, array $data=[]): OperatorRecord
        {
            return new OperatorRecord(array_merge([
                'uuid' => $uuid,
                'name' => 'operator_' . substr($uuid, 0, 8),
                'access_token' => hash('sha256', $uuid),
                'disabled' => 0,
                'client_permissions' => 0,
                'management_permissions' => 0,
                'operator_permissions' => 0,
                'auto_assign_reports' => 0,
                'auto_assign_automated_reports' => 0,
                'is_bot' => 0,
                'created' => '2026-01-01 00:00:00',
                'updated' => '2026-01-02 00:00:00',
            ], $data));
        }

        public function testDatabaseRow(): void
        {
            $record = $this->createRecord('11111111-1111-7111-8111-111111111111', [
                'client_permissions' => 1,
                'auto_assign_reports' => 1,
                'auto_assign_automated_reports' => 0,
                'is_bot' => 1,
            ]);

            $this->assertTrue($record->hasClientPermissions());
            $this->assertFalse($record->hasManagementPermissions());
            $this->assertFalse($record->hasOperatorPermissions());
            $this->assertTrue($record->isAutoAssignReports());
            $this->assertFalse($record->isAutoAssignAutomatedReports());
            $this->assertTrue($record->isAutoAssigned());
            $this->assertTrue($record->isBot());
            $this->assertSame(strtotime('2026-01-01 00:00:00'), $record->getCreated());
            $this->assertSame(strtotime('2026-01-02 00:00:00'), $record->getUpdated());
        }

        public function testManagementInheritsClientPermissions(): void
        {
            $record = $this->createRecord('11111111-1111-7111-8111-111111111111', ['management_permissions' => 1]);

            $this->assertTrue($record->hasClientPermissions());
            $this->assertTrue($record->getPermissions()->hasClient());
            $this->assertSame(['client' => true, 'management' => true, 'operator' => false], $record->toStandardArray()['permissions']);
        }

        public function testPermissionsObject(): void
        {
            $permissions = new OperatorPermissions(false, true, true);

            $this->assertTrue($permissions->hasClient());
            $this->assertTrue($permissions->hasManagement());
            $this->assertTrue($permissions->hasOperator());
            $this->assertSame(['client' => true, 'management' => true, 'operator' => true], $permissions->toArray());
            $this->assertSame(['client' => false, 'management' => false, 'operator' => true], OperatorPermissions::fromArray(['operator' => true])->toArray());
        }

        public function testConfigurationObject(): void
        {
            $configuration = new OperatorConfiguration(true, false, true);

            $this->assertTrue($configuration->isAutoAssignReports());
            $this->assertFalse($configuration->isAutoAssignAutomatedReports());
            $this->assertTrue($configuration->isBot());
            $this->assertSame(['auto_assign_reports' => true, 'auto_assign_automated_reports' => false, 'is_bot' => true], $configuration->toArray());
        }

        public function testStandardArrayShape(): void
        {
            $record = $this->createRecord('11111111-1111-7111-8111-111111111111');
            $array = $record->toStandardArray($record);

            $this->assertSame(['uuid', 'name', 'disabled', 'permissions', 'configuration', 'created', 'updated'], array_keys($array));
            // The access token and the flat columns of the database are never part of the OFD object
            $this->assertArrayNotHasKey('access_token', $array);
            $this->assertArrayNotHasKey('client_permissions', $array);
            $this->assertArrayNotHasKey('auto_assign', $array);
            $this->assertArrayNotHasKey('auto_assign_reports', $array);
            $this->assertArrayNotHasKey('is_bot', $array);
        }

        public function testConfigurationVisibleToTheOperatorItself(): void
        {
            $record = $this->createRecord('11111111-1111-7111-8111-111111111111', ['is_bot' => 1, 'auto_assign_automated_reports' => 1]);

            $this->assertTrue($record->isConfigurationVisibleTo($record));
            $this->assertSame(
                ['auto_assign_reports' => false, 'auto_assign_automated_reports' => true, 'is_bot' => true],
                $record->toStandardArray($record)['configuration']
            );
        }

        public function testConfigurationVisibleToOperatorPermissions(): void
        {
            $record = $this->createRecord('11111111-1111-7111-8111-111111111111', ['auto_assign_reports' => 1]);
            $viewer = $this->createRecord('22222222-2222-7222-8222-222222222222', ['operator_permissions' => 1]);

            $this->assertTrue($record->isConfigurationVisibleTo($viewer));
            $this->assertSame(
                ['auto_assign_reports' => true, 'auto_assign_automated_reports' => false, 'is_bot' => false],
                $record->toStandardArray($viewer)['configuration']
            );
        }

        public function testConfigurationHiddenFromOthers(): void
        {
            $record = $this->createRecord('11111111-1111-7111-8111-111111111111', ['is_bot' => 1]);
            $viewers = [
                'anonymous' => null,
                'client' => $this->createRecord('33333333-3333-7333-8333-333333333333', ['client_permissions' => 1]),
                'management' => $this->createRecord('44444444-4444-7444-8444-444444444444', ['management_permissions' => 1]),
                'none' => $this->createRecord('55555555-5555-7555-8555-555555555555'),
            ];

            foreach($viewers as $name => $viewer)
            {
                $this->assertFalse($record->isConfigurationVisibleTo($viewer), $name);

                $array = $record->toStandardArray($viewer);
                // The member is present and null rather than omitted
                $this->assertArrayHasKey('configuration', $array, $name);
                $this->assertNull($array['configuration'], $name);
                // Permissions remain visible
                $this->assertSame(['client' => false, 'management' => false, 'operator' => false], $array['permissions'], $name);
            }

            $this->assertNull($record->toStandardArray()['configuration']);
        }

        public function testHostResponseWithConfiguration(): void
        {
            $record = new OperatorRecord([
                'uuid' => '11111111-1111-7111-8111-111111111111',
                'name' => 'bot',
                'disabled' => false,
                'permissions' => ['client' => true, 'management' => false, 'operator' => false],
                'configuration' => ['auto_assign_reports' => false, 'auto_assign_automated_reports' => true, 'is_bot' => true],
                'created' => 1767225600,
                'updated' => 1767225600,
            ]);

            $this->assertTrue($record->hasClientPermissions());
            $this->assertTrue($record->isBot());
            $this->assertTrue($record->isAutoAssignAutomatedReports());
            $this->assertNotNull($record->getConfiguration());
            $this->assertTrue($record->getConfiguration()->isBot());
            $this->assertSame(1767225600, $record->getCreated());
        }

        public function testHostResponseWithHiddenConfiguration(): void
        {
            // A host returns null to requesters that may not see the configuration, it is unknown rather than false
            $record = new OperatorRecord([
                'uuid' => '11111111-1111-7111-8111-111111111111',
                'name' => 'someone',
                'disabled' => false,
                'permissions' => ['client' => true, 'management' => false, 'operator' => false],
                'configuration' => null,
                'created' => 1767225600,
                'updated' => 1767225600,
            ]);

            $this->assertNull($record->getConfiguration());
            $this->assertFalse($record->isBot());
            $this->assertTrue($record->hasClientPermissions());
        }

        public function testRecordHoldsItsPermissionsAndConfiguration(): void
        {
            $record = $this->createRecord('11111111-1111-7111-8111-111111111111', ['operator_permissions' => 1, 'is_bot' => 1]);

            // The record holds its OperatorPermissions and OperatorConfiguration objects rather than building new ones
            $this->assertInstanceOf(OperatorPermissions::class, $record->getPermissions());
            $this->assertSame($record->getPermissions(), $record->getPermissions());
            $this->assertInstanceOf(OperatorConfiguration::class, $record->getConfiguration());
            $this->assertSame($record->getConfiguration(), $record->getConfiguration());

            $this->assertSame($record->getPermissions()->hasOperator(), $record->hasOperatorPermissions());
            $this->assertSame($record->getConfiguration()->isBot(), $record->isBot());
        }

        public function testDatabaseRowConfigurationIsKnown(): void
        {
            $record = $this->createRecord('11111111-1111-7111-8111-111111111111');
            $this->assertNotNull($record->getConfiguration());
        }

        public function testRoundTripThroughStandardArray(): void
        {
            $record = $this->createRecord('11111111-1111-7111-8111-111111111111', [
                'management_permissions' => 1,
                'auto_assign_reports' => 1,
                'is_bot' => 1,
            ]);

            $parsed = new OperatorRecord($record->toStandardArray($record));

            $this->assertSame($record->toStandardArray($record), $parsed->toStandardArray($parsed));
        }

        public function testCachedRecordRoundTrip(): void
        {
            // Records are cached flat (toArray), with every value as a string when read back from Redis
            $record = $this->createRecord('11111111-1111-7111-8111-111111111111', ['client_permissions' => 1, 'auto_assign_automated_reports' => 1, 'is_bot' => 1]);
            $cached = array_map(fn($value) => is_bool($value) ? ($value ? '1' : '0') : (string)$value, $record->toArray());

            $restored = new OperatorRecord($cached);

            $this->assertSame($record->toArray(), $restored->toArray());
        }
    }
