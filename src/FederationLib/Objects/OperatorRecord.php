<?php
    
    namespace FederationLib\Objects;
    
    use DateTime;
    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use FederationLib\Interfaces\StandardObjectInterface;
    use FederationLib\Objects\OperatorRecord\OperatorConfiguration;
    use FederationLib\Objects\OperatorRecord\OperatorPermissions;

    class OperatorRecord implements StandardObjectInterface, ObjectSpecificationInterface
    {
        private string $uuid;
        private ?string $accessToken;
        private string $name;
        private bool $disabled;
        private OperatorPermissions $permissions;
        private ?OperatorConfiguration $configuration;
        private int $created;
        private int $updated;

        /**
         * OperatorRecord constructor.
         *
         * @param array $data Associative array of operator data.
         */
        public function __construct(array $data)
        {
            $this->uuid = $data['uuid'] ?? '';
            $this->accessToken = $data['access_token'] ?? '';
            $this->name = $data['name'] ?? '';
            $this->disabled = (bool)($data['disabled'] ?? false);

            // A record carries the OperatorPermissions and OperatorConfiguration objects of the OFD OperatorRecord object
            // (a response of a host), or their flat columns (a database row or a cached record)
            if(is_array($data['permissions'] ?? null))
            {
                $this->permissions = OperatorPermissions::fromArray($data['permissions']);
            }
            else
            {
                $this->permissions = new OperatorPermissions(
                    (bool)($data['client_permissions'] ?? false),
                    (bool)($data['management_permissions'] ?? false),
                    (bool)($data['operator_permissions'] ?? false)
                );
            }

            // A host only returns the configuration to the operator itself and to operators with operator permissions,
            // to anyone else it is null and therefore unknown
            if(is_array($data['configuration'] ?? null))
            {
                $this->configuration = OperatorConfiguration::fromArray($data['configuration']);
            }
            elseif(array_key_exists('configuration', $data))
            {
                $this->configuration = null;
            }
            else
            {
                $this->configuration = new OperatorConfiguration(
                    (bool)($data['auto_assign_reports'] ?? false),
                    (bool)($data['auto_assign_automated_reports'] ?? false),
                    (bool)($data['is_bot'] ?? false)
                );
            }

            // Parse SQL datetime string to timestamp if necessary for created
            if (isset($data['created']) && is_string($data['created']))
            {
                // Numeric strings come from the Redis cache (hGetAll returns all hash values as strings)
                $data['created'] = is_numeric($data['created']) ? (int)$data['created'] : strtotime($data['created']);
            }
            elseif (isset($data['created']) && $data['created'] instanceof DateTime)
            {
                $data['created'] = $data['created']->getTimestamp();
            }
            else
            {
                $data['created'] = $data['created'] ?? time();
            }

            // Parse SQL datetime string to timestamp if necessary for updated
            if (isset($data['updated']) && is_string($data['updated']))
            {
                // Numeric strings come from the Redis cache (hGetAll returns all hash values as strings)
                $data['updated'] = is_numeric($data['updated']) ? (int)$data['updated'] : strtotime($data['updated']);
            }
            elseif (isset($data['updated']) && $data['updated'] instanceof DateTime)
            {
                $data['updated'] = $data['updated']->getTimestamp();
            }
            else
            {
                $data['updated'] = $data['updated'] ?? time();
            }

            $this->created = (int)($data['created'] ?? time());
            $this->updated = (int)($data['updated'] ?? time());
        }

        /**
         * Get the UUID of the operator.
         *
         * @return string
         */
        public function getUuid(): string
        {
            return $this->uuid;
        }

        /**
         * Get the Access Token of the operator.
         *
         * @return string|null
         */
        public function getAccessToken(): ?string
        {
            return $this->accessToken;
        }

        /**
         * Clears the Access Token from the object, used to censor the sensitive information
         *
         * @return void
         */
        public function clearAccessToken(): void
        {
            $this->accessToken = null;
        }

        /**
         * Get the name of the operator.
         *
         * @return string
         */
        public function getName(): string
        {
            return $this->name;
        }

        /**
         * Check if the operator is disabled.
         *
         * @return bool
         */
        public function isDisabled(): bool
        {
            return $this->disabled;
        }

        /**
         * Check if the operator has client permissions (management operators inherit client permissions).
         *
         * @return bool
         */
        public function hasClientPermissions(): bool
        {
            return $this->permissions->hasClient();
        }

        /**
         * Check if the operator has management permissions.
         *
         * @return bool
         */
        public function hasManagementPermissions(): bool
        {
            return $this->permissions->hasManagement();
        }

        /**
         * Check if the operator has operator permissions.
         *
         * @return bool
         */
        public function hasOperatorPermissions(): bool
        {
            return $this->permissions->hasOperator();
        }

        /**
         * Check if the operator opts in to the automatic assignment of any kind of report
         *
         * @return bool True if the operator opts in to ordinary or automated reports, false if the configuration is unknown
         */
        public function isAutoAssigned(): bool
        {
            return $this->isAutoAssignReports() || $this->isAutoAssignAutomatedReports();
        }

        /**
         * Check if the operator opts in to the automatic assignment of reports that are not automated
         *
         * @return bool False if the configuration is unknown
         */
        public function isAutoAssignReports(): bool
        {
            return $this->configuration?->isAutoAssignReports() ?? false;
        }

        /**
         * Check if the operator opts in to the automatic assignment of automated reports
         *
         * @return bool False if the configuration is unknown
         */
        public function isAutoAssignAutomatedReports(): bool
        {
            return $this->configuration?->isAutoAssignAutomatedReports() ?? false;
        }

        /**
         * Check if the operator is a bot, the evidence and reports it submits are automated and its evidence is created
         * confidential (OFD "Automated Records")
         *
         * @return bool False if the configuration is unknown
         */
        public function isBot(): bool
        {
            return $this->configuration?->isBot() ?? false;
        }

        /**
         * Returns the permission groups of the operator, with client permissions in effect
         *
         * @return OperatorPermissions The permissions
         */
        public function getPermissions(): OperatorPermissions
        {
            return $this->permissions;
        }

        /**
         * Returns the configuration of the operator
         *
         * @return OperatorConfiguration|null The configuration, null if the host did not disclose it to the requester
         */
        public function getConfiguration(): ?OperatorConfiguration
        {
            return $this->configuration;
        }

        /**
         * Checks whether the given operator may see the configuration of this operator: the operator itself and
         * operators with operator permissions may, anyone else may not (OFD "Visibility Rules")
         *
         * @param OperatorRecord|null $viewer The operator making the request, null if the request is anonymous
         * @return bool True if the viewer may see the configuration
         */
        public function isConfigurationVisibleTo(?OperatorRecord $viewer): bool
        {
            return $viewer !== null && ($viewer->getUuid() === $this->uuid || $viewer->hasOperatorPermissions());
        }

        /**
         * Get the creation timestamp.
         *
         * @return int
         */
        public function getCreated(): int
        {
            return $this->created;
        }

        /**
         * Get the last updated timestamp.
         *
         * @return int
         */
        public function getUpdated(): int
        {
            return $this->updated;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'uuid' => $this->uuid,
                'access_token' => $this->accessToken,
                'name' => $this->name,
                'disabled' => $this->disabled,
                'client_permissions' => $this->permissions->hasClient(),
                'management_permissions' => $this->permissions->hasManagement(),
                'operator_permissions' => $this->permissions->hasOperator(),
                'auto_assign_reports' => $this->isAutoAssignReports(),
                'auto_assign_automated_reports' => $this->isAutoAssignAutomatedReports(),
                'is_bot' => $this->isBot(),
                'created' => $this->created,
                'updated' => $this->updated
            ];
        }

        /**
         * @inheritDoc
         * @param OperatorRecord|null $viewer The operator making the request, the configuration is only included when
         *                                    it is this operator or holds operator permissions, and is null otherwise
         */
        public function toStandardArray(?OperatorRecord $viewer=null): array
        {
            return [
                'uuid' => $this->uuid,
                'name' => $this->name,
                'disabled' => $this->disabled,
                'permissions' => $this->permissions->toArray(),
                'configuration' => $this->isConfigurationVisibleTo($viewer) ? $this->configuration?->toArray() : null,
                'created' => $this->created,
                'updated' => $this->updated
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): OperatorRecord
        {
            if(isset($array['created']) )
            {
                if(is_string($array['created']))
                {
                    $array['created'] = is_numeric($array['created']) ? (int)$array['created'] : strtotime($array['created']);
                }
                if($array['created'] instanceof DateTime)
                {
                    $array['created'] = $array['created']->getTimestamp();
                }
            }


            if(isset($array['updated']) )
            {
                if(is_string($array['updated']))
                {
                    $array['updated'] = is_numeric($array['updated']) ? (int)$array['updated'] : strtotime($array['updated']);
                }
                if($array['updated'] instanceof DateTime)
                {
                    $array['updated'] = $array['updated']->getTimestamp();
                }
            }

            return new self($array);
        }

        /**
         * @inheritDoc
         */
        public static function getObjectType(): string
        {
            return 'object';
        }

        /**
         * @inheritDoc
         */
        public static function getObjectProperties(): array
        {
            return [
                'uuid' => ['type' => 'string', 'format' => 'uuid', 'description' => 'Unique identifier for the operator'],
                'name' => ['type' => 'string', 'description' => 'Display name of the operator'],
                'disabled' => ['type' => 'boolean', 'description' => 'Whether the operator account is disabled'],
                'permissions' => ['$ref' => OperatorPermissions::getReference(), 'description' => 'The permission groups the operator holds'],
                'configuration' => [
                    'anyOf' => [['$ref' => OperatorConfiguration::getReference()], ['type' => 'null']],
                    'description' => 'The configuration of the operator, null unless the requester is the operator itself or holds operator permissions',
                ],
                'created' => ['type' => 'integer', 'description' => 'Unix timestamp when the operator was created'],
                'updated' => ['type' => 'integer', 'description' => 'Unix timestamp when the operator was last updated'],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getObjectRequired(): array
        {
            return ['uuid', 'name', 'disabled', 'permissions', 'configuration', 'created', 'updated'];
        }

        /**
         * @inheritDoc
         */
        public static function getReference(): string
        {
            return '#/components/schemas/OperatorRecord';
        }
    }
