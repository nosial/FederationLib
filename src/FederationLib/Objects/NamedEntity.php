<?php

    namespace FederationLib\Objects;

    use FederationLib\Interfaces\SerializableInterface;
    use InvalidArgumentException;

    class NamedEntity implements SerializableInterface
    {
        private NamedEntityPosition $entityPosition;
        private EntityQueryResult $queryResult;

        /**
         * Public constructor for the NamedEntity object
         *
         * @param NamedEntityPosition $entityPosition The entity position that was extracted from the text content that was scanned
         * @param EntityQueryResult $queryResult The query result of the entity that was found.
         */
        public function __construct(NamedEntityPosition $entityPosition, EntityQueryResult $queryResult)
        {
            $this->entityPosition = $entityPosition;
            $this->queryResult = $queryResult;
        }

        /**
         * Returns the position details of the named entity that was extracted
         *
         * @return NamedEntityPosition The position details of the named
         */
        public function getEntityPosition(): NamedEntityPosition
        {
            return $this->entityPosition;
        }

        /**
         * Returns the query result of the named entity
         *
         * @return EntityQueryResult The query result of the named entity
         */
        public function getQueryResult(): EntityQueryResult
        {
            return $this->queryResult;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'entity_position' => $this->entityPosition->toArray(),
                'query_result' => $this->queryResult->toArray()
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): NamedEntity
        {
            if(!isset($array['entity_position']))
            {
                throw new InvalidArgumentException('Missing required field \'entity_position\'');
            }

            if(!isset($array['query_result']))
            {
                throw new InvalidArgumentException('Missing required field \'query_result\'');
            }

            return new self(
                entityPosition: NamedEntityPosition::fromArray($array['entity_position']),
                queryResult: EntityQueryResult::fromArray($array['query_result'])
            );
        }
    }