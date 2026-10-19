<?php

    namespace FederationLib\Enums;

    use FederationLib\Classes\Validate;
    use FederationLib\Interfaces\CaseSensitiveInterface;
    use InvalidArgumentException;

    /**
     * The kind of content carried by a ContentInput object (OFD "Content type values"), which tells the host how to
     * interpret the content and which context keys may accompany it.
     */
    enum ContentType : string implements CaseSensitiveInterface
    {
        /**
         * Free-form text, such as a chat message. The default, and the only interpretation in OFD 1.0.
         */
        case TEXT = 'TEXT';

        /**
         * An email message, the text content is the body of the message.
         */
        case EMAIL = 'EMAIL';

        /**
         * A text message received over a telephone network (SMS/MMS), the text content is the body of the message.
         */
        case SMS = 'SMS';

        /**
         * A web page, the text content is text extracted from the page by the client. The url context key is required.
         */
        case WEB_PAGE = 'WEB_PAGE';

        /**
         * A DNS name observed by the client, for example in a DNS lookup. The text content is the name.
         */
        case HOSTNAME = 'HOSTNAME';

        /**
         * A URL observed by the client, the text content is an absolute URL.
         */
        case URL = 'URL';

        /**
         * The maximum size of a context object in bytes, the same as entity metadata (OFD "Entity Metadata")
         */
        private const int MAX_CONTEXT_SIZE = 8000;

        /**
         * @inheritDoc
         */
        public static function tryFromCaseInsensitive(string $value): ?ContentType
        {
            return self::tryFrom(strtoupper($value));
        }

        /**
         * Returns the context keys defined for the content type (OFD "Content context")
         *
         * @return string[] The context keys
         */
        public function getContextKeys(): array
        {
            return match($this)
            {
                self::EMAIL => ['subject', 'sender', 'reply_to'],
                self::SMS => ['region', 'sender_id'],
                self::WEB_PAGE => ['url', 'final_url', 'title', 'description', 'language'],
                self::HOSTNAME => ['query_type'],
                self::TEXT, self::URL => [],
            };
        }

        /**
         * Returns the context keys that are required for the content type
         *
         * @return string[] The required context keys
         */
        public function getRequiredContextKeys(): array
        {
            return match($this)
            {
                self::WEB_PAGE => ['url'],
                default => [],
            };
        }

        /**
         * Normalizes the text content before it is validated, stored and analysed. A DNS name is converted to
         * lowercase and a single trailing dot is removed, as OFD permits for the HOSTNAME content type.
         *
         * @param string $textContent The text content
         * @return string The normalized text content
         */
        public function normalizeTextContent(string $textContent): string
        {
            if($this !== self::HOSTNAME)
            {
                return $textContent;
            }

            $textContent = strtolower($textContent);
            if(str_ends_with($textContent, '.'))
            {
                $textContent = substr($textContent, 0, -1);
            }

            return $textContent;
        }

        /**
         * Validates the text content and context of a ContentInput object of this content type
         *
         * @param string|null $textContent The text content, already normalized with normalizeTextContent()
         * @param array|null $context The context, null when absent
         * @return void
         * @throws InvalidArgumentException If the text content or the context is not valid for the content type
         */
        public function validate(?string $textContent, ?array $context): void
        {
            if($textContent !== null && $textContent !== '')
            {
                if($this === self::HOSTNAME && !Validate::dnsName($textContent))
                {
                    throw new InvalidArgumentException('The text content of a HOSTNAME must be a DNS name');
                }

                if($this === self::URL && !Validate::absoluteUrl($textContent))
                {
                    throw new InvalidArgumentException('The text content of a URL must be an absolute URL');
                }
            }

            // A JSON array decodes to integer keys, which validateContext() rejects like any key that is not a string
            $context ??= [];
            self::validateContext($context);

            $allowedKeys = $this->getContextKeys();
            foreach($context as $key => $value)
            {
                if(!in_array($key, $allowedKeys, true))
                {
                    throw new InvalidArgumentException(sprintf('The context key "%s" is not defined for the %s content type', $key, $this->value));
                }
            }

            foreach($this->getRequiredContextKeys() as $key)
            {
                if(!isset($context[$key]))
                {
                    throw new InvalidArgumentException(sprintf('The context of the %s content type requires the "%s" key', $this->value, $key));
                }
            }

            foreach(['url', 'final_url'] as $key)
            {
                if(isset($context[$key]) && !Validate::httpUrl($context[$key]))
                {
                    throw new InvalidArgumentException(sprintf('The context key "%s" must be an absolute HTTP or HTTPS URL', $key));
                }
            }

            if($this === self::SMS && isset($context['region']) && preg_match('/^[A-Za-z]{2}$/', $context['region']) !== 1)
            {
                throw new InvalidArgumentException('The context key "region" must be an ISO 3166-1 alpha-2 code');
            }
        }

        /**
         * Validates the constraints every context object is subject to: the key, value and size constraints of entity
         * metadata, with every value a non-empty string (OFD "Content context")
         *
         * @param array $context The context
         * @return void
         * @throws InvalidArgumentException If the context violates the constraints
         */
        private static function validateContext(array $context): void
        {
            foreach($context as $key => $value)
            {
                if(!is_string($key))
                {
                    throw new InvalidArgumentException('The context must be an object');
                }

                if(strlen($key) < 1 || strlen($key) > 64)
                {
                    throw new InvalidArgumentException('Context keys must be 1 to 64 bytes long');
                }

                if(!is_string($value) || $value === '' || strlen($value) > 1000)
                {
                    throw new InvalidArgumentException(sprintf('The context key "%s" must be a non-empty string of at most 1000 bytes', $key));
                }
            }

            if(count($context) > 0 && strlen(json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > self::MAX_CONTEXT_SIZE)
            {
                throw new InvalidArgumentException('The context must not exceed 8000 bytes');
            }
        }
    }
