<?php

    namespace FederationLib\Objects;

    use FederationLib\Interfaces\SerializableInterface;
    use FederationLib\Objects\BayesianAnalytics\AnalyticalEntry;
    use InvalidArgumentException;

    class BayesianAnalytics implements SerializableInterface
    {
        private array $entries;
        private int $total;
        private int $returned;
        private int $offset;
        private int $limit;

        /**
         * Constructs a BayesianAnalytics instance from an array
         *
         * @param array $array Data array with keys: entries, total, returned, offset, limit
         */
        public function __construct(array $array)
        {
            $this->entries = [];
            $this->total = (int)$array['total'];
            $this->returned = (int)$array['returned'];
            $this->offset = (int)$array['offset'];
            $this->limit = (int)$array['limit'];

            if(isset($array['entries']))
            {
                if(is_string($array['entries']) && $array['entries'] !== '')
                {
                    $array['entries'] = json_decode($array['entries'], true);
                }

                foreach($array['entries'] as $entry)
                {
                    if($entry instanceof AnalyticalEntry)
                    {
                        $this->entries[] = $entry;
                    }
                    elseif(is_array($entry))
                    {
                        $this->entries[] = AnalyticalEntry::fromArray($entry);
                    }
                    elseif($entry === null)
                    {
                        $this->entries = [];
                    }
                    else
                    {
                        throw new InvalidArgumentException('Unexpected type: ' . gettype($entry));
                    }
                }
            }
        }

        /**
         * Returns the analytics entries
         *
         * @return AnalyticalEntry[] The list of analytical entries
         */
        public function getEntries(): array
        {
            return $this->entries;
        }

        /**
         * Returns the total number of records
         *
         * @return int Total records count
         */
        public function getTotal(): int
        {
            return $this->total;
        }

        /**
         * Returns the number of returned records
         *
         * @return int Returned records count
         */
        public function getReturned(): int
        {
            return $this->returned;
        }

        /**
         * Returns the offset used for pagination
         *
         * @return int The offset value
         */
        public function getOffset(): int
        {
            return $this->offset;
        }

        /**
         * Returns the limit used for pagination
         *
         * @return int The limit value
         */
        public function getLimit(): int
        {
            return $this->limit;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'entries' => array_map(fn($entry) => $entry->toArray(), $this->entries),
                'total' => $this->total,
                'returned' => $this->returned,
                'offset' => $this->offset,
                'limit' => $this->limit
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): BayesianAnalytics
        {
            return new self($array);
        }
    }