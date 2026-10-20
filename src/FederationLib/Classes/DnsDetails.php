<?php

    namespace FederationLib\Classes;

    class DnsDetails
    {
        public const string KEY_PREFIX = 'dns_';
        public const string KEY_RESOLVED = 'dns_resolved';
        public const string KEY_STATUS = 'dns_status';
        public const string STATUS_RESOLVED = 'RESOLVED';
        public const string STATUS_NO_RECORDS = 'NO_RECORDS';
        public const string STATUS_FAILED = 'FAILED';

        /**
         * The maximum length of a metadata string value (see Validate::metadata())
         */
        private const int MAX_VALUE_LENGTH = 1000;

        /**
         * Resolves the A, AAAA, CNAME, MX and NS records of a DNS name
         *
         * @param string $name The DNS name
         * @param int|null $resolved Optional. The time of the lookup, the current time by default
         * @return array<string, string|int> The DNS details as entity metadata keys
         */
        public static function lookup(string $name, ?int $resolved=null): array
        {
            $resolved ??= time();
            $records = @dns_get_record(strtolower(rtrim($name, '.')) . '.', DNS_A | DNS_AAAA | DNS_CNAME | DNS_MX | DNS_NS);
            return self::fromRecords($records, $resolved);
        }

        /**
         * Converts the records returned by dns_get_record() to the DNS details
         *
         * @param array|false $records The records, false if the lookup failed
         * @param int $resolved The time of the lookup
         * @return array<string, string|int> The DNS details as entity metadata keys
         */
        public static function fromRecords(array|false $records, int $resolved): array
        {
            if($records === false)
            {
                return [self::KEY_RESOLVED => $resolved, self::KEY_STATUS => self::STATUS_FAILED];
            }

            $values = ['a' => [], 'aaaa' => [], 'cname' => [], 'mx' => [], 'ns' => []];
            foreach($records as $record)
            {
                if(!is_array($record))
                {
                    continue;
                }

                switch($record['type'] ?? null)
                {
                    case 'A':
                        if(isset($record['ip']) && filter_var($record['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)
                        {
                            $values['a'][$record['ip']] = $record['ip'];
                        }
                        break;

                    case 'AAAA':
                        // IPv6 addresses in their compressed lowercase form, as entity hosts are
                        $packed = isset($record['ipv6']) && is_string($record['ipv6']) ? @inet_pton($record['ipv6']) : false;
                        if($packed !== false && strlen($packed) === 16)
                        {
                            $address = inet_ntop($packed);
                            $values['aaaa'][$address] = $address;
                        }
                        break;

                    case 'CNAME':
                    case 'NS':
                        $target = self::normalizeName($record['target'] ?? null);
                        if($target !== null)
                        {
                            $values[strtolower($record['type'])][$target] = $target;
                        }
                        break;

                    case 'MX':
                        $target = self::normalizeName($record['target'] ?? null);
                        if($target !== null)
                        {
                            $values['mx'][$target] = [(int)($record['pri'] ?? 0), $target];
                        }
                        break;
                }
            }

            // MX records in order of preference
            uasort($values['mx'], fn(array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
            $values['mx'] = array_map(fn(array $mx) => $mx[0] . ' ' . $mx[1], $values['mx']);
            foreach(['a', 'aaaa', 'cname', 'ns'] as $type)
            {
                sort($values[$type]);
            }

            $details = [self::KEY_RESOLVED => $resolved, self::KEY_STATUS => self::STATUS_NO_RECORDS];
            foreach($values as $type => $list)
            {
                $value = self::join(array_values($list));
                if($value !== null)
                {
                    $details[self::KEY_PREFIX . $type] = $value;
                    $details[self::KEY_STATUS] = self::STATUS_RESOLVED;
                }
            }

            return $details;
        }

        /**
         * Returns whether a metadata key is one of the DNS details
         *
         * @param string $key The metadata key
         * @return bool True if the key holds DNS details
         */
        public static function isKey(string $key): bool
        {
            return str_starts_with($key, self::KEY_PREFIX);
        }

        /**
         * Normalizes a DNS name of a record: lowercase without a trailing dot
         *
         * @param mixed $name The name
         * @return string|null The name, null if it is empty or not a string
         */
        private static function normalizeName(mixed $name): ?string
        {
            if(!is_string($name))
            {
                return null;
            }

            $name = strtolower(rtrim(trim($name), '.'));
            return $name !== '' ? $name : null;
        }

        /**
         * Joins values with commas, keeping as many whole values as fit in a metadata string value
         *
         * @param string[] $values The values
         * @return string|null The joined values, null if there are none
         */
        private static function join(array $values): ?string
        {
            $joined = '';
            foreach($values as $value)
            {
                $candidate = $joined === '' ? $value : $joined . ',' . $value;
                if(strlen($candidate) > self::MAX_VALUE_LENGTH)
                {
                    break;
                }

                $joined = $candidate;
            }

            return $joined !== '' ? $joined : null;
        }
    }
