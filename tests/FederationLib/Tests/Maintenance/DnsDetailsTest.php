<?php

    namespace FederationLib\Tests\Maintenance;

    use FederationLib\Classes\Configuration\MaintenanceConfiguration;
    use FederationLib\Classes\DnsDetails;
    use FederationLib\Classes\Validate;
    use PHPUnit\Framework\TestCase;

    /**
     * The DNS details maintenance stores in the metadata of domain entities, built from records in the form
     * dns_get_record() returns them, no name is resolved here
     */
    class DnsDetailsTest extends TestCase
    {
        public function testRecords(): void
        {
            $details = DnsDetails::fromRecords([
                ['host' => 'example.com', 'type' => 'A', 'ip' => '93.184.216.34'],
                ['host' => 'example.com', 'type' => 'A', 'ip' => '93.184.216.34'],
                ['host' => 'example.com', 'type' => 'A', 'ip' => '23.192.228.80'],
                ['host' => 'example.com', 'type' => 'AAAA', 'ipv6' => '2606:2800:220:1:248:1893:25C8:1946'],
                ['host' => 'example.com', 'type' => 'CNAME', 'target' => 'Edge.Example.NET.'],
                ['host' => 'example.com', 'type' => 'MX', 'pri' => 20, 'target' => 'mx2.example.com'],
                ['host' => 'example.com', 'type' => 'MX', 'pri' => 10, 'target' => 'mx1.example.com'],
                ['host' => 'example.com', 'type' => 'NS', 'target' => 'b.iana-servers.net'],
                ['host' => 'example.com', 'type' => 'NS', 'target' => 'a.iana-servers.net'],
            ], 1767225600);

            $this->assertSame([
                'dns_resolved' => 1767225600,
                'dns_status' => 'RESOLVED',
                'dns_a' => '23.192.228.80,93.184.216.34',
                'dns_aaaa' => '2606:2800:220:1:248:1893:25c8:1946',
                'dns_cname' => 'edge.example.net',
                'dns_mx' => '10 mx1.example.com,20 mx2.example.com',
                'dns_ns' => 'a.iana-servers.net,b.iana-servers.net',
            ], $details);
        }

        public function testOnlyPresentTypesAreIncluded(): void
        {
            $details = DnsDetails::fromRecords([['host' => 'example.com', 'type' => 'A', 'ip' => '192.0.2.1']], 100);

            $this->assertSame(['dns_resolved' => 100, 'dns_status' => 'RESOLVED', 'dns_a' => '192.0.2.1'], $details);
        }

        public function testNoRecords(): void
        {
            $this->assertSame(['dns_resolved' => 100, 'dns_status' => 'NO_RECORDS'], DnsDetails::fromRecords([], 100));
        }

        public function testFailedLookup(): void
        {
            $this->assertSame(['dns_resolved' => 100, 'dns_status' => 'FAILED'], DnsDetails::fromRecords(false, 100));
        }

        public function testInvalidRecordsAreIgnored(): void
        {
            $details = DnsDetails::fromRecords([
                'not a record',
                ['type' => 'A', 'ip' => 'not an address'],
                ['type' => 'A', 'ip' => '2001:db8::1'],
                ['type' => 'AAAA', 'ipv6' => '192.0.2.1'],
                ['type' => 'AAAA'],
                ['type' => 'NS', 'target' => ''],
                ['type' => 'MX', 'target' => null],
                ['type' => 'TXT', 'txt' => 'v=spf1 -all'],
                ['ip' => '192.0.2.1'],
            ], 100);

            $this->assertSame(['dns_resolved' => 100, 'dns_status' => 'NO_RECORDS'], $details);
        }

        public function testLongListsKeepWholeValuesWithinTheValueLimit(): void
        {
            $records = [];
            for($i = 0; $i < 200; $i++)
            {
                $records[] = ['type' => 'A', 'ip' => sprintf('10.%d.%d.%d', intdiv($i, 100), $i % 100, 1)];
            }

            $details = DnsDetails::fromRecords($records, 100);

            $this->assertLessThanOrEqual(1000, strlen($details['dns_a']));
            foreach(explode(',', $details['dns_a']) as $address)
            {
                $this->assertNotFalse(filter_var($address, FILTER_VALIDATE_IP), $address);
            }
        }

        public function testDetailsAreValidMetadata(): void
        {
            $details = DnsDetails::fromRecords([
                ['type' => 'A', 'ip' => '192.0.2.1'],
                ['type' => 'MX', 'pri' => 5, 'target' => 'mail.example.com'],
            ], 100);

            $this->assertTrue(Validate::metadata($details));
            foreach(array_keys($details) as $key)
            {
                $this->assertTrue(DnsDetails::isKey($key), $key);
            }
            $this->assertFalse(DnsDetails::isKey('carrier'));
        }

        public function testRefreshDefaultsToThreeMonths(): void
        {
            $configuration = new MaintenanceConfiguration([]);

            $this->assertTrue($configuration->isDnsRefreshEnabled());
            $this->assertSame(90 * 86400, $configuration->getDnsRefresh());
        }

        public function testRefreshInterval(): void
        {
            $this->assertSame(30 * 86400, (new MaintenanceConfiguration(['dns_refresh' => '30d']))->getDnsRefresh());
            $this->assertSame(3600, (new MaintenanceConfiguration(['dns_refresh' => 3600]))->getDnsRefresh());
            // An interval that cannot be parsed keeps the default
            $this->assertSame(90 * 86400, (new MaintenanceConfiguration(['dns_refresh' => 'often']))->getDnsRefresh());
        }

        public function testRefreshCanBeDisabled(): void
        {
            foreach(['off', 'never', false, null, 0, '0'] as $value)
            {
                $configuration = new MaintenanceConfiguration(['dns_refresh' => $value]);
                $this->assertFalse($configuration->isDnsRefreshEnabled(), var_export($value, true));
                $this->assertNull($configuration->getDnsRefresh());
            }
        }
    }
