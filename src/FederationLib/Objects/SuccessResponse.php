<?php

    namespace FederationLib\Objects;

    use FederationLib\Interfaces\ResponseInterface;
    use InvalidArgumentException;

    class SuccessResponse implements ResponseInterface
    {
        private mixed $data;

        /**
         * Constructor for SuccessResponse
         *
         * @param mixed $data The data to include in the success response
         * @throws InvalidArgumentException If the data is not provided
         */
        public function __construct(mixed $data)
        {
            $this->data = $data;
        }

        /**
         * @inheritDoc
         */
        public function isSuccess(): bool
        {
            return true;
        }

        /**
         * Returns the data response
         *
         * @return mixed
         */
        public function getData(): mixed
        {
            return $this->data;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'success' => true,
                'data' => $this->data,
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): SuccessResponse
        {
            if (!isset($array['success']) || !$array['success'])
            {
                throw new InvalidArgumentException("Array must contain 'success' key set to true");
            }

            if (!isset($array['data']))
            {
                throw new InvalidArgumentException("Array must contain 'data' key");
            }

            return new self($array['data']);
        }
    }