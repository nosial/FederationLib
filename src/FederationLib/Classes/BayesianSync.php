<?php

    namespace FederationLib\Classes;

    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Exceptions\DatabaseOperationException;

    /**
     * Replays the classifications of evidence in the order they were made, so BayesianServer learns every
     * classification even when it missed some (eg; it was unavailable or its learning queue was full when the
     * evidence was classified). The position of the last replayed classification is kept in the database, so a sync
     * only replays what was classified since.
     */
    class BayesianSync
    {
        private const string POSITION_KEY = 'bayesian_sync';
        private const int PAGE_SIZE = 500;

        /**
         * Returns the position of the last replayed classification
         * @return array{0: string, 1: string}|null The classification time and the evidence UUID, null to start from
         *                                          the first classification
         * @throws DatabaseOperationException If the position could not be read
         */
        public static function getPosition(): ?array
        {
            $value = DatabaseConnection::getMetadata(self::POSITION_KEY);
            if($value === null || !str_contains($value, '|'))
            {
                return null;
            }

            return explode('|', $value, 2);
        }

        /**
         * Stores the position of the last replayed classification
         *
         * @param array{0: string, 1: string}|null $position The classification time and the evidence UUID, null to
         *                                                   replay every classification on the next sync
         * @return void
         * @throws DatabaseOperationException If the position could not be written
         */
        public static function setPosition(?array $position): void
        {
            DatabaseConnection::setMetadata(self::POSITION_KEY, $position === null ? '' : implode('|', $position));
        }

        /**
         * Replays the classifications made after the given position, in the order they were made
         *
         * @param array{0: string, 1: string}|null $position The position to replay from, null for every classification
         * @param callable(string, string): bool $learn Learns a classification (text and classification flag), returns
         *                                             false to stop (eg; the learning queue is full)
         * @return array{replayed: int, position: array|null, complete: bool} The number of replayed classifications,
         *                                                                    the position of the last one and whether
         *                                                                    every classification was replayed
         * @throws DatabaseOperationException If the classifications could not be retrieved
         */
        public static function replay(?array $position, callable $learn): array
        {
            $replayed = 0;
            while(count($rows = EvidenceManager::getClassifiedSince($position[0] ?? null, $position[1] ?? null, self::PAGE_SIZE)) > 0)
            {
                foreach($rows as $row)
                {
                    if(!$learn($row['text_content'], $row['classification_flag']))
                    {
                        return ['replayed' => $replayed, 'position' => $position, 'complete' => false];
                    }

                    $position = [$row['classified'], $row['uuid']];
                    $replayed++;
                }
            }

            return ['replayed' => $replayed, 'position' => $position, 'complete' => true];
        }
    }
