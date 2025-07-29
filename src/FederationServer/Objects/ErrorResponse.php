<?php

    namespace FederationServer\Objects;

    use FederationServer\Enums\HttpResponseCode;
    use FederationServer\Interfaces\ResponseInterface;
    use InvalidArgumentException;

    class ErrorResponse implements ResponseInterface
    {
        private HttpResponseCode $code;
        private string $message;

        /**
         * Constructor for ErrorResponse
         *
         * @param int|HttpResponseCode $code The HTTP response code
         * @param string $message The error message
         */
        public function __construct(int|HttpResponseCode $code, string $message)
        {
            if(is_int($code))
            {
                $code = HttpResponseCode::tryFrom($code);

                if($code === null)
                {
                    throw new InvalidArgumentException("Invalid HTTP response code: $code");
                }
            }

            $this->code = $code;
            $this->message = $message;
        }

        /**
         * Get the HTTP response code
         *
         * @return HttpResponseCode The HTTP response code
         */
        public function getCode(): HttpResponseCode
        {
            return $this->code;
        }

        /**
         * Get the error message
         *
         * @return string The error message
         */
        /**
         * @return string
         */
        public function getMessage(): string
        {
            return $this->message;
        }

        /**
         * @inheritDoc
         */
        public function isSuccess(): bool
        {
            return false;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'success' => false,
                'code' => $this->code->value,
                'message' => $this->message,
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): ErrorResponse
        {
            if (!isset($array['success']) || $array['success'] !== false)
            {
                throw new InvalidArgumentException("Array must contain 'success' key set to false");
            }

            if (!isset($array['code']) || !is_int($array['code']))
            {
                throw new InvalidArgumentException("Array must contain 'code' key with an integer value");
            }

            if (!isset($array['message']) || !is_string($array['message']))
            {
                throw new InvalidArgumentException("Array must contain 'message' key with a string value");
            }

            return new self(HttpResponseCode::from($array['code']), $array['message']);
        }
    }