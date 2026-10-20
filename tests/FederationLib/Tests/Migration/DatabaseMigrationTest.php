<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace FederationLib\Tests\Migration;

    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Helpers\TestHelpers;
    use PHPUnit\Framework\TestCase;

    /**
     * The upgrade of a fully populated database of FederationLib 1.0.14 (schema version 1.0) to the current schema, as
     * `federationlib init` performs it on startup. The TestPlugin builds the 1.0.14 database in a scratch database of
     * the test environment, migrates it, and compares it with a database created fresh from the current table
     * definitions (see tests/TestPlugin/src/TestPlugin/MigrationCheck.php); the database of the server is not touched.
     */
    class DatabaseMigrationTest extends TestCase
    {
        use TestHelpers;

        private static ?array $result = null;

        /**
         * Runs the migration check once for every test of this class
         */
        private function getResult(): array
        {
            if(self::$result === null)
            {
                [$code, $body] = $this->jsonRequest('POST', '/test-plugin/migration-check', $this->rootToken());
                $this->assertSame(200, $code, 'The migration check failed: ' . json_encode($body));
                self::$result = $body;
            }

            return self::$result;
        }

        public function testEveryMigrationRuns(): void
        {
            $result = $this->getResult();

            $this->assertSame('1.0', $result['migrated_from']);
            $this->assertSame(['1.1', '1.2', '1.3', '1.4'], $result['migrations']);
            $this->assertSame(DatabaseConnection::SCHEMA_VERSION, $result['schema_version']);
        }

        public function testMigratedSchemaMatchesFreshSchema(): void
        {
            $this->assertSame([], $this->getResult()['differences'], implode("\n", $this->getResult()['differences']));
        }

        public function testRecordsAreCarriedOver(): void
        {
            $this->assertSame([], $this->getResult()['data_errors'], implode("\n", $this->getResult()['data_errors']));
        }

        public function testMigrationCheckRouteRequiresPermissions(): void
        {
            [$code] = $this->rawRequest('POST', '/test-plugin/migration-check');
            $this->assertSame(401, $code);
        }
    }
