<?php

    namespace FederationLib\Objects\Plugin;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Enums\RetrievalPurpose;
    use FederationLib\Exceptions\RemoteRetrievalRejectedException;
    use FederationLib\Objects\OperatorRecord;
    use FederationLib\Objects\RemoteRetrievalContext;
    use InvalidArgumentException;

    class RemoteRetrievalRequest
    {
        private string $url;
        private string $host;
        private int $port;
        private ?string $address;
        private string $originalUrl;
        private int $redirects;
        private int $deadline;
        private RemoteRetrievalContext $context;
        private bool $denied;

        /**
         * RemoteRetrievalRequest constructor.
         *
         * @param string $url The normalized URL about to be retrieved
         * @param string $host The host of the URL
         * @param int $port The port of the URL
         * @param string|null $address The address the server connects to, null when the host is an IP address literal
         * @param string $originalUrl The URL the client supplied, before any redirect
         * @param int $redirects The number of redirects followed to reach the URL, 0 for the URL the client supplied
         * @param int $deadline The Unix timestamp by which the retrieval must end
         * @param RemoteRetrievalContext $context Why and on whose behalf the URL is retrieved
         */
        public function __construct(string $url, string $host, int $port, ?string $address, string $originalUrl, int $redirects, int $deadline, RemoteRetrievalContext $context)
        {
            $this->url = $url;
            $this->host = $host;
            $this->port = $port;
            $this->address = $address;
            $this->originalUrl = $originalUrl;
            $this->redirects = $redirects;
            $this->deadline = $deadline;
            $this->context = $context;
            $this->denied = false;
        }

        /**
         * Returns the normalized URL about to be retrieved, an assertion of the client
         *
         * @return string The URL
         */
        public function getUrl(): string
        {
            return $this->url;
        }

        /**
         * Returns the host of the URL
         *
         * @return string The host, lowercase
         */
        public function getHost(): string
        {
            return $this->host;
        }

        /**
         * Returns the port of the URL
         *
         * @return int The port
         */
        public function getPort(): int
        {
            return $this->port;
        }

        /**
         * Returns the address the server connects to, the handler must never connect to it itself
         *
         * @return string|null The IP address, null when the host is an IP address literal
         */
        public function getAddress(): ?string
        {
            return $this->address;
        }

        /**
         * Returns the URL the client supplied, before any redirect
         *
         * @return string The URL
         */
        public function getOriginalUrl(): string
        {
            return $this->originalUrl;
        }

        /**
         * Returns the number of redirects followed to reach the URL
         *
         * @return int The number of redirects, 0 for the URL the client supplied
         */
        public function getRedirects(): int
        {
            return $this->redirects;
        }

        /**
         * Returns the number of seconds left before the retrieval's deadline, a handler should finish within them
         *
         * @return int The seconds left, 0 once the deadline passed
         */
        public function getRemainingTime(): int
        {
            return max(0, $this->deadline - time());
        }

        /**
         * Returns why the URL is retrieved
         *
         * @return RetrievalPurpose The purpose
         */
        public function getPurpose(): RetrievalPurpose
        {
            return $this->context->getPurpose();
        }

        /**
         * Returns the operator that supplied the URL
         *
         * @return OperatorRecord|null The operator, null if anonymous
         */
        public function getOperator(): ?OperatorRecord
        {
            return $this->context->getOperator();
        }

        /**
         * Returns why and on whose behalf the URL is retrieved, including the entity, evidence and file it relates to
         *
         * @return RemoteRetrievalContext The context
         */
        public function getContext(): RemoteRetrievalContext
        {
            return $this->context;
        }

        /**
         * Denies the URL: it's treated as a URL that is not permitted. A URL upload is rejected (400) and a file URL of a
         * content scan is left out silently. A denial can't be undone, the remaining handlers are not executed
         *
         * @return void
         */
        public function deny(): void
        {
            $this->denied = true;
        }

        /**
         * Returns True if a handler denied the URL
         *
         * @return bool True if denied
         */
        public function isDenied(): bool
        {
            return $this->denied;
        }

        /**
         * Rejects the request that would retrieve the URL, the client receives an error response and nothing is
         * retrieved
         *
         * @param string $message The error message sent to the client
         * @param HttpResponseCode $code A 4xx HTTP status code, 403 by default
         * @return never
         * @throws RemoteRetrievalRejectedException Always, to reject the request
         * @throws InvalidArgumentException If the HTTP status code is not a 4xx client error
         */
        public function reject(string $message, HttpResponseCode $code=HttpResponseCode::FORBIDDEN): never
        {
            if($code->value < 400 || $code->value > 499)
            {
                throw new InvalidArgumentException(sprintf('A remote retrieval can only be rejected with a 4xx client error, %d given', $code->value));
            }

            throw new RemoteRetrievalRejectedException($message, $code);
        }
    }
