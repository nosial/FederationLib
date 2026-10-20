<?php

    namespace FederationLib\Classes\Managers;

    use FederationLib\Classes\Configuration;
    use FederationLib\Classes\DatabaseConnection;
    use FederationLib\Classes\Logger;
    use FederationLib\Classes\RedisConnection;
    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\EntityRelationshipType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Objects\ScannedContent;
    use PDO;
    use PDOException;
    use Redis;
    use RedisException;
    use Throwable;

    class ReputationManager
    {
        /**
         * The reputation above which gains taper towards the maximum
         */
        private const int TAPER_START = 500;

        /**
         * The number of activity rows settled per query
         */
        private const int BATCH_SIZE = 1000;

        /**
         * How many days settled activity is kept before it is removed
         */
        private const int RETENTION_DAYS = 7;

        /**
         * The prefix of the keys of the activity buffered in Redis:
         *  - reputation_activity:days, the days with buffered activity
         *  - reputation_activity:<day>:entities, the entities with buffered activity on a day
         *  - reputation_activity:<day>:<entity>, the activity counters of an entity on a day
         *  - reputation_activity:<day>:<entity>:authors, the distinct authors that mentioned an entity on a day
         */
        private const string BUFFER_PREFIX = 'reputation_activity:';

        /**
         * The counter columns of entity_activity
         */
        private const array COUNTERS = ['normal_scans', 'caution_scans', 'blocked_scans', 'normal_mentions', 'flagged_mentions'];

        /**
         * Records the activity of a scan made by an authenticated client: of the author of the content, and of the
         * entities mentioned in it. Failures are logged, they never fail the scan.
         *
         * @param ScannedContent $scannedContent The fully constructed scan result
         * @return void
         */
        public static function recordScan(ScannedContent $scannedContent): void
        {
            $configuration = Configuration::getScanningConfiguration();
            $riskScore = $scannedContent->getRiskScore();
            $blocked = $riskScore >= $configuration->getActionBlockThreshold();
            $caution = $blocked || $riskScore >= $configuration->getActionCautionThreshold();
            $day = gmdate('Y-m-d');

            $author = $scannedContent->getAuthorEntity();
            $authorUuid = $author?->getEntity()->getUuid();

            // The counters to increment, keyed by entity, and the mentions to record
            $increments = [];
            $mentionAuthors = [];
            if($author !== null)
            {
                // A blacklisted author's normal content does not earn it reputation while the blacklist is in effect
                $increments[$authorUuid] = $blocked ? 'blocked_scans' : (($caution || count($author->getActiveBlacklists()) > 0) ? 'caution_scans' : 'normal_scans');
            }

            // Only entities found in the content itself count as mentions, not those resolved from the context the
            // client asserted or from the addresses the server resolved
            foreach($scannedContent->getResolvedEntities() as $resolvedEntity)
            {
                $uuid = $resolvedEntity->getEntity()->getUuid();
                if($resolvedEntity->getEntityPosition() === null || $uuid === $authorUuid || isset($increments[$uuid]))
                {
                    continue;
                }

                $increments[$uuid] = $caution ? 'flagged_mentions' : 'normal_mentions';
                if(!$caution && $authorUuid !== null)
                {
                    $mentionAuthors[$uuid] = $authorUuid;
                }
            }

            if(count($increments) === 0)
            {
                return;
            }

            if(self::bufferActivity($day, $increments, $mentionAuthors))
            {
                return;
            }

            try
            {
                foreach($increments as $uuid => $column)
                {
                    self::increment($uuid, $day, $column);
                }

                foreach($mentionAuthors as $uuid => $mentionAuthor)
                {
                    self::recordMentionAuthor($uuid, $day, $mentionAuthor);
                }
            }
            catch(DatabaseOperationException $e)
            {
                Logger::log()->error('Failed to record the reputation activity of a scan: ' . $e->getMessage(), $e);
            }
        }

        /**
         * Settles every completed day of recorded activity into reputation, at most one adjustment per entity per day,
         * and removes settled activity older than a week. Days are settled in order, so running it late or more than
         * once never applies a day twice.
         *
         * @param int|null $now Optional. The current time, the current day is never settled
         * @return array{days: int, gains: int, losses: int} The number of settled days and of applied gains and losses
         * @throws DatabaseOperationException If the activity cannot be read or updated, or the buffered activity cannot
         *                                    be written
         */
        public static function settle(?int $now=null): array
        {
            $now ??= time();

            // The buffered activity is written first, a day settled without it would never count it
            self::flushBuffer($now);

            $today = gmdate('Y-m-d', $now);
            $result = ['days' => 0, 'gains' => 0, 'losses' => 0];

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("SELECT DISTINCT day FROM entity_activity WHERE settled = 0 AND day < :today ORDER BY day");
                $stmt->bindValue(':today', $today);
                $stmt->execute();
                $days = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
            catch(PDOException $e)
            {
                throw new DatabaseOperationException('Failed to read the unsettled reputation activity: ' . $e->getMessage(), 0, $e);
            }

            foreach($days as $day)
            {
                $settled = self::settleDay((string)$day);
                $result['days']++;
                $result['gains'] += $settled['gains'];
                $result['losses'] += $settled['losses'];
            }

            self::prune(gmdate('Y-m-d', $now - self::RETENTION_DAYS * 86400));
            return $result;
        }

        /**
         * Removes the unsettled activity of an entity, used when its reputation is cleared so that activity recorded
         * before does not count afterwards
         *
         * @param string $entityUuid The entity UUID
         * @return void
         * @throws DatabaseOperationException If the activity cannot be removed
         */
        public static function clearActivity(string $entityUuid): void
        {
            self::clearBufferedActivity($entityUuid);

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM entity_activity WHERE entity = :entity AND settled = 0");
                $stmt->bindValue(':entity', $entityUuid);
                $stmt->execute();

                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM entity_mention_authors WHERE entity = :entity");
                $stmt->bindValue(':entity', $entityUuid);
                $stmt->execute();
            }
            catch(PDOException $e)
            {
                throw new DatabaseOperationException('Failed to clear the reputation activity of an entity: ' . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Returns the reputation an entity gains for a day of normal activity: the configured gain below 500, tapering
         * linearly to 1 point towards 1000
         *
         * @param int $reputation The current reputation of the entity
         * @return int The gain, 0 when the entity is at the maximum reputation or gains are disabled
         */
        public static function getGain(int $reputation): int
        {
            $configuration = Configuration::getScanningConfiguration();
            $gain = $configuration->getReputationGain();
            $maxBound = $configuration->getReputationMaxBound();
            if($gain === 0 || $reputation >= $maxBound)
            {
                return 0;
            }

            if($reputation <= self::TAPER_START)
            {
                return $gain;
            }

            return max(1, (int)round($gain * ($maxBound - $reputation) / ($maxBound - self::TAPER_START)));
        }

        /**
         * Settles one day of activity
         *
         * @param string $day The day (Y-m-d)
         * @return array{gains: int, losses: int} The number of applied gains and losses
         * @throws DatabaseOperationException If the activity cannot be read or updated
         */
        private static function settleDay(string $day): array
        {
            $configuration = Configuration::getScanningConfiguration();
            $result = ['gains' => 0, 'losses' => 0];

            // The adjustment of each entity for the day, and the entities whose own activity holds back a gain
            $deltas = [];
            $held = [];

            do
            {
                try
                {
                    $stmt = DatabaseConnection::getConnection()->prepare(
                        "SELECT a.*, (SELECT COUNT(*) FROM entity_mention_authors m WHERE m.entity = a.entity AND m.day = a.day) AS mention_authors
                         FROM entity_activity a WHERE a.settled = 0 AND a.day = :day ORDER BY a.entity LIMIT :limit"
                    );
                    $stmt->bindValue(':day', $day);
                    $stmt->bindValue(':limit', self::BATCH_SIZE, PDO::PARAM_INT);
                    $stmt->execute();
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
                catch(PDOException $e)
                {
                    throw new DatabaseOperationException('Failed to read the reputation activity of ' . $day . ': ' . $e->getMessage(), 0, $e);
                }

                // The entities of the batch at once, from the cache where they are cached
                $entities = EntitiesManager::getEntitiesByUuids(array_column($rows, 'entity'));
                foreach($rows as $row)
                {
                    $entity = $entities[$row['entity']] ?? null;
                    if($entity === null)
                    {
                        continue;
                    }

                    if((int)$row['blocked_scans'] > 0)
                    {
                        // Content the entity authored reached the block threshold, the day costs it reputation
                        $deltas[$entity->getUuid()] = $configuration->getReputationLoss();
                        $held[$entity->getUuid()] = true;
                    }
                    elseif((int)$row['caution_scans'] > 0 || (int)$row['flagged_mentions'] > 0)
                    {
                        // A day with content that warranted caution earns nothing
                        $held[$entity->getUuid()] = true;
                    }
                    elseif((int)$row['normal_scans'] > 0 || (int)$row['mention_authors'] >= $configuration->getMentionMinAuthors())
                    {
                        $deltas[$entity->getUuid()] = max($deltas[$entity->getUuid()] ?? 0, self::getGain($entity->getReputation()));

                        // A subdomain or a dependent entity (CHILD) also credits its parent for the day
                        $parentUuid = $entity->getRelationshipEntity();
                        if($parentUuid !== null && $entity->getRelationshipType() === EntityRelationshipType::CHILD)
                        {
                            $deltas[$parentUuid] ??= null;
                        }
                    }
                }

                self::markSettled(array_column($rows, 'entity'), $day);
            }
            while(count($rows) === self::BATCH_SIZE);

            $parents = EntitiesManager::getEntitiesByUuids(array_keys(array_filter($deltas, fn($delta) => $delta === null)));
            foreach($deltas as $uuid => $delta)
            {
                // A parent credited by its children gains unless its own activity of the day holds the gain back
                if($delta === null)
                {
                    if(isset($held[$uuid]))
                    {
                        continue;
                    }

                    $parent = $parents[$uuid] ?? null;
                    $delta = $parent !== null ? self::getGain($parent->getReputation()) : 0;
                }

                if($delta === 0)
                {
                    continue;
                }

                try
                {
                    EntitiesManager::updateEntityReputation($uuid, $delta);
                    $result[$delta > 0 ? 'gains' : 'losses']++;
                }
                catch(DatabaseOperationException $e)
                {
                    Logger::log()->warning(sprintf('Failed to settle the reputation of entity %s for %s: %s', $uuid, $day, $e->getMessage()), $e);
                }
            }

            return $result;
        }

        /**
         * Buffers the activity of a scan in Redis in a single pipelined round trip. Every key expires after the
         * retention of settled activity, so a buffer that is never flushed does not grow without bounds.
         *
         * @param string $day The day (Y-m-d)
         * @param array<string, string> $increments The counter to increment for each entity UUID
         * @param array<string, string> $mentionAuthors The author that mentioned each entity UUID in normal content
         * @return bool True if the activity was buffered, false if Redis is disabled or failed
         */
        private static function bufferActivity(string $day, array $increments, array $mentionAuthors): bool
        {
            if(!Configuration::getRedisConfiguration()->isEnabled())
            {
                return false;
            }

            try
            {
                $redis = RedisConnection::getConnection();
                $ttl = self::RETENTION_DAYS * 86400;
                $entitiesKey = self::BUFFER_PREFIX . $day . ':entities';

                $pipeline = $redis->pipeline();
                foreach($increments as $uuid => $column)
                {
                    $key = self::BUFFER_PREFIX . $day . ':' . $uuid;
                    $pipeline->hIncrBy($key, $column, 1);
                    $pipeline->expire($key, $ttl);
                    $pipeline->sAdd($entitiesKey, $uuid);
                }

                foreach($mentionAuthors as $uuid => $author)
                {
                    $key = self::BUFFER_PREFIX . $day . ':' . $uuid . ':authors';
                    $pipeline->sAdd($key, $author);
                    $pipeline->expire($key, $ttl);
                }

                $pipeline->expire($entitiesKey, $ttl);
                $pipeline->sAdd(self::BUFFER_PREFIX . 'days', $day);
                $pipeline->exec();
                return true;
            }
            catch(Throwable $e)
            {
                Logger::log()->warning('Failed to buffer the reputation activity of a scan, writing it to the database: ' . $e->getMessage(), $e);
                return false;
            }
        }

        /**
         * Writes the activity buffered in Redis to the database, in batches of BATCH_SIZE entities. Each entity is
         * taken from the buffer atomically, so scans buffering activity meanwhile and concurrent flushes never lose or
         * double count activity. A batch that cannot be written is put back into the buffer.
         *
         * @param int $now The current time, the buffer of the current day remains listed for the scans still to come
         * @return void
         * @throws DatabaseOperationException If the buffered activity cannot be written
         */
        private static function flushBuffer(int $now): void
        {
            if(!Configuration::getRedisConfiguration()->isEnabled())
            {
                return;
            }

            try
            {
                $redis = RedisConnection::getConnection();
                $days = $redis->sMembers(self::BUFFER_PREFIX . 'days') ?: [];
            }
            catch(Throwable $e)
            {
                // Without the buffer, the activity written to the database directly is still settled
                Logger::log()->warning('Failed to read the buffered reputation activity: ' . $e->getMessage(), $e);
                return;
            }

            $today = gmdate('Y-m-d', $now);
            sort($days);
            try
            {
                self::flushDays($redis, $days, $today);
            }
            catch(RedisException $e)
            {
                // A day settled while part of its activity is still buffered would never count it
                throw new DatabaseOperationException('Failed to read the buffered reputation activity: ' . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Writes the buffered activity of the given days to the database (see flushBuffer())
         *
         * @param Redis $redis The Redis connection
         * @param string[] $days The days with buffered activity, in ascending order
         * @param string $today The current day (Y-m-d)
         * @return void
         * @throws DatabaseOperationException If the buffered activity cannot be written
         * @throws RedisException If the buffered activity cannot be read
         */
        private static function flushDays(Redis $redis, array $days, string $today): void
        {
            foreach($days as $day)
            {
                $entitiesKey = self::BUFFER_PREFIX . $day . ':entities';
                while(!empty($uuids = $redis->sPop($entitiesKey, self::BATCH_SIZE) ?: []))
                {
                    $counters = [];
                    $authors = [];
                    foreach($uuids as $uuid)
                    {
                        $key = self::BUFFER_PREFIX . $day . ':' . $uuid;
                        [$hash, , $members] = $redis->multi()->hGetAll($key)->del($key)->sMembers($key . ':authors')->del($key . ':authors')->exec();
                        if(is_array($hash) && count($hash) > 0)
                        {
                            $counters[$uuid] = array_map('intval', array_intersect_key($hash, array_flip(self::COUNTERS)));
                        }

                        if(is_array($members) && count($members) > 0)
                        {
                            $authors[$uuid] = $members;
                        }
                    }

                    try
                    {
                        self::writeActivity($day, $counters, $authors);
                    }
                    catch(DatabaseOperationException $e)
                    {
                        self::restoreBuffer($day, $counters, $authors);
                        throw $e;
                    }
                }

                // The scans of the current day keep buffering activity, it remains listed until the day is over
                if($day < $today)
                {
                    $redis->sRem(self::BUFFER_PREFIX . 'days', $day);
                }
            }
        }

        /**
         * Writes a batch of buffered activity to the database: the counters are added to those already written, and
         * the mention authors recorded
         *
         * @param string $day The day (Y-m-d)
         * @param array<string, array<string, int>> $counters The counters of each entity UUID
         * @param array<string, string[]> $authors The distinct authors that mentioned each entity UUID
         * @return void
         * @throws DatabaseOperationException If the activity cannot be written
         */
        private static function writeActivity(string $day, array $counters, array $authors): void
        {
            // An entity removed since its activity was buffered has no activity to settle
            $existing = array_keys(EntitiesManager::getEntitiesByUuids(array_merge(array_keys($counters), array_keys($authors), ...array_values($authors))));
            $counters = array_intersect_key($counters, array_flip($existing));

            try
            {
                // One row per entity, the day of each row is bound on its own as placeholders are not reused
                foreach(array_chunk(array_keys($counters), self::BATCH_SIZE) as $uuids)
                {
                    $values = [];
                    $parameters = [];
                    foreach($uuids as $index => $uuid)
                    {
                        $placeholders = [":e$index", ":d$index"];
                        $parameters[":e$index"] = $uuid;
                        $parameters[":d$index"] = $day;
                        foreach(self::COUNTERS as $column)
                        {
                            $placeholders[] = ":{$column}_$index";
                            $parameters[":{$column}_$index"] = $counters[$uuid][$column] ?? 0;
                        }
                        $values[] = '(' . implode(', ', $placeholders) . ')';
                    }

                    $updates = implode(', ', array_map(fn(string $column) => "$column = $column + VALUES($column)", self::COUNTERS));
                    $stmt = DatabaseConnection::getConnection()->prepare(
                        'INSERT INTO entity_activity (entity, day, ' . implode(', ', self::COUNTERS) . ') VALUES ' . implode(', ', $values) . " ON DUPLICATE KEY UPDATE $updates"
                    );
                    foreach($parameters as $placeholder => $value)
                    {
                        $stmt->bindValue($placeholder, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
                    }
                    $stmt->execute();
                }

                // The mentions of entities and by authors that still exist, the table references both
                $mentions = [];
                foreach($authors as $uuid => $members)
                {
                    foreach($members as $author)
                    {
                        if(in_array($uuid, $existing, true) && in_array($author, $existing, true))
                        {
                            $mentions[] = [$uuid, $author];
                        }
                    }
                }

                foreach(array_chunk($mentions, self::BATCH_SIZE) as $chunk)
                {
                    $values = [];
                    $parameters = [];
                    foreach($chunk as $index => [$uuid, $author])
                    {
                        $values[] = "(:e$index, :d$index, :a$index)";
                        $parameters[":e$index"] = $uuid;
                        $parameters[":d$index"] = $day;
                        $parameters[":a$index"] = $author;
                    }

                    $stmt = DatabaseConnection::getConnection()->prepare('INSERT IGNORE INTO entity_mention_authors (entity, day, author) VALUES ' . implode(', ', $values));
                    foreach($parameters as $placeholder => $value)
                    {
                        $stmt->bindValue($placeholder, $value);
                    }
                    $stmt->execute();
                }
            }
            catch(PDOException $e)
            {
                throw new DatabaseOperationException('Failed to write the buffered reputation activity: ' . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Puts activity taken from the buffer back, after it could not be written to the database
         *
         * @param string $day The day (Y-m-d)
         * @param array<string, array<string, int>> $counters The counters of each entity UUID
         * @param array<string, string[]> $authors The distinct authors that mentioned each entity UUID
         * @return void
         */
        private static function restoreBuffer(string $day, array $counters, array $authors): void
        {
            try
            {
                $redis = RedisConnection::getConnection();
                $ttl = self::RETENTION_DAYS * 86400;
                $pipeline = $redis->pipeline();
                foreach($counters as $uuid => $values)
                {
                    $key = self::BUFFER_PREFIX . $day . ':' . $uuid;
                    foreach($values as $column => $value)
                    {
                        $pipeline->hIncrBy($key, $column, $value);
                    }
                    $pipeline->expire($key, $ttl);
                    $pipeline->sAdd(self::BUFFER_PREFIX . $day . ':entities', $uuid);
                }

                foreach($authors as $uuid => $members)
                {
                    $pipeline->sAdd(self::BUFFER_PREFIX . $day . ':' . $uuid . ':authors', ...$members);
                    $pipeline->expire(self::BUFFER_PREFIX . $day . ':' . $uuid . ':authors', $ttl);
                    $pipeline->sAdd(self::BUFFER_PREFIX . $day . ':entities', $uuid);
                }

                $pipeline->exec();
            }
            catch(Throwable $e)
            {
                Logger::log()->error('Failed to restore buffered reputation activity that could not be written, the activity is lost: ' . $e->getMessage(), $e);
            }
        }

        /**
         * Removes the buffered activity of an entity, and its mentions
         *
         * @param string $entityUuid The entity UUID
         * @return void
         */
        private static function clearBufferedActivity(string $entityUuid): void
        {
            if(!Configuration::getRedisConfiguration()->isEnabled())
            {
                return;
            }

            try
            {
                $redis = RedisConnection::getConnection();
                foreach($redis->sMembers(self::BUFFER_PREFIX . 'days') ?: [] as $day)
                {
                    $key = self::BUFFER_PREFIX . $day . ':' . $entityUuid;
                    $redis->multi()->sRem(self::BUFFER_PREFIX . $day . ':entities', $entityUuid)->del($key, $key . ':authors')->exec();
                }
            }
            catch(Throwable $e)
            {
                Logger::log()->warning('Failed to clear the buffered reputation activity of an entity: ' . $e->getMessage(), $e);
            }
        }

        /**
         * Increments a counter of the activity of an entity for a day
         *
         * @param string $entityUuid The entity UUID
         * @param string $day The day (Y-m-d)
         * @param string $column The counter, one of the counter columns of entity_activity
         * @return void
         * @throws DatabaseOperationException If the activity cannot be recorded
         */
        private static function increment(string $entityUuid, string $day, string $column): void
        {
            if(!in_array($column, self::COUNTERS, true))
            {
                return;
            }

            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare(
                    "INSERT INTO entity_activity (entity, day, $column) VALUES (:entity, :day, 1) ON DUPLICATE KEY UPDATE $column = $column + 1"
                );
                $stmt->bindValue(':entity', $entityUuid);
                $stmt->bindValue(':day', $day);
                $stmt->execute();
            }
            catch(PDOException $e)
            {
                throw new DatabaseOperationException('Failed to record reputation activity: ' . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Records that an author mentioned an entity in normal content on a day
         *
         * @param string $entityUuid The mentioned entity UUID
         * @param string $day The day (Y-m-d)
         * @param string $authorUuid The author entity UUID
         * @return void
         * @throws DatabaseOperationException If the mention cannot be recorded
         */
        private static function recordMentionAuthor(string $entityUuid, string $day, string $authorUuid): void
        {
            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("INSERT IGNORE INTO entity_mention_authors (entity, day, author) VALUES (:entity, :day, :author)");
                $stmt->bindValue(':entity', $entityUuid);
                $stmt->bindValue(':day', $day);
                $stmt->bindValue(':author', $authorUuid);
                $stmt->execute();
            }
            catch(PDOException $e)
            {
                throw new DatabaseOperationException('Failed to record a mention: ' . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Marks the activity of entities for a day as settled
         *
         * @param string[] $entityUuids The entity UUIDs
         * @param string $day The day (Y-m-d)
         * @return void
         * @throws DatabaseOperationException If the activity cannot be updated
         */
        private static function markSettled(array $entityUuids, string $day): void
        {
            if(count($entityUuids) === 0)
            {
                return;
            }

            [$placeholders, $parameters] = Utilities::getInPlaceholders($entityUuids, 'entity');
            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("UPDATE entity_activity SET settled = 1 WHERE day = :day AND entity IN ($placeholders)");
                $stmt->bindValue(':day', $day);
                foreach($parameters as $placeholder => $value)
                {
                    $stmt->bindValue($placeholder, $value);
                }
                $stmt->execute();
            }
            catch(PDOException $e)
            {
                throw new DatabaseOperationException('Failed to mark reputation activity as settled: ' . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Removes settled activity and mentions of days before the given day
         *
         * @param string $before The first day to keep (Y-m-d)
         * @return void
         * @throws DatabaseOperationException If the activity cannot be removed
         */
        private static function prune(string $before): void
        {
            try
            {
                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM entity_activity WHERE settled = 1 AND day < :before");
                $stmt->bindValue(':before', $before);
                $stmt->execute();

                $stmt = DatabaseConnection::getConnection()->prepare("DELETE FROM entity_mention_authors WHERE day < :before");
                $stmt->bindValue(':before', $before);
                $stmt->execute();
            }
            catch(PDOException $e)
            {
                throw new DatabaseOperationException('Failed to remove settled reputation activity: ' . $e->getMessage(), 0, $e);
            }
        }
    }
