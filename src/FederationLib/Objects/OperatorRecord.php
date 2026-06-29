<?php
    
    namespace FederationLib\Objects;
    
    use DateTime;
    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use FederationLib\Interfaces\SerializableInterface;

    class OperatorRecord implements SerializableInterface, ObjectSpecificationInterface
    {
        private string $uuid;
        private ?string $accessToken;
        private string $name;
        private bool $disabled;
        private bool $clientPermissions;
        private bool $managementPermissions;
        private bool $operatorPermissions;
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
            $this->clientPermissions = (bool)($data['client_permissions'] ?? false);
            $this->managementPermissions = (bool)($data['management_permissions'] ?? false);
            $this->operatorPermissions = (bool)($data['operator_permissions'] ?? false);

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
         * Check if the operator has client permissions (inherits management_permissions).
         *
         * @return bool
         */
        public function hasClientPermissions(): bool
        {
            return $this->clientPermissions || $this->managementPermissions;
        }

        /**
         * Check if the operator has management permissions.
         *
         * @return bool
         */
        public function hasManagementPermissions(): bool
        {
            return $this->managementPermissions;
        }

        /**
         * Check if the operator has operator permissions.
         *
         * @return bool
         */
        public function hasOperatorPermissions(): bool
        {
            return $this->operatorPermissions;
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
                'client_permissions' => $this->clientPermissions,
                'management_permissions' => $this->managementPermissions,
                'operator_permissions' => $this->operatorPermissions,
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
                'access_token' => ['type' => 'string', 'description' => 'Access token for authentication', 'nullable' => true],
                'name' => ['type' => 'string', 'description' => 'Display name of the operator'],
                'disabled' => ['type' => 'boolean', 'description' => 'Whether the operator account is disabled'],
                'client_permissions' => ['type' => 'boolean', 'description' => 'Whether the operator has client-level permissions'],
                'management_permissions' => ['type' => 'boolean', 'description' => 'Whether the operator has management-level permissions'],
                'operator_permissions' => ['type' => 'boolean', 'description' => 'Whether the operator has operator-level permissions'],
                'created' => ['type' => 'integer', 'description' => 'Unix timestamp when the operator was created'],
                'updated' => ['type' => 'integer', 'description' => 'Unix timestamp when the operator was last updated'],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getObjectRequired(): array
        {
            return ['uuid', 'name', 'disabled', 'client_permissions', 'management_permissions', 'operator_permissions', 'created', 'updated'];
        }

        /**
         * @inheritDoc
         */
        public static function getReference(): string
        {
            return '#/components/schemas/OperatorRecord';
        }
    }
