<?php

    namespace FederationLib\Classes;

    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Enums\NamedEntityType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Objects\NamedEntity;
    use FederationLib\Objects\NamedEntityPosition;

    class NamedEntityExtractor
    {
        /**
         * Scans the given text content with a configurable limit
         *
         * @param string $text The text content to scan for entities
         * @param int $limit The max amount of items that can be returned, 0 means it will do a full scan-content.
         *                   depending on the amount of entities that has been extracted, the scan could take longer.
         * @return NamedEntity[] The array result of the extracted named entities and their positions
         * @throws DatabaseOperationException Thrown if there was an error with the databases operation
         */
        public static function scanContent(string $text, int $limit=0): array
        {
            // First extract the named entities from the text content
            $extractedEntityPositions = self::extractEntities($text);
            if(empty($extractedEntityPositions))
            {
                return [];
            }

            $results = [];

            foreach($extractedEntityPositions as $entityPosition)
            {
                if($limit > 0 && count($results) >= $limit)
                {
                    // Break early if the limit has been reached
                    break;
                }

                // Parse by entity position type
                switch($entityPosition->getType())
                {
                    // Domains, IP Addresses are handled the same; they're all singleton hosts
                    case NamedEntityType::DOMAIN:
                    case NamedEntityType::IPv4:
                    case NamedEntityType::IPv6:
                        $entityRecord = EntitiesManager::getEntityByHash(Utilities::hashEntity($entityPosition->getValue()));
                        if($entityRecord !== null)
                        {
                            $results[] = new NamedEntity($entityPosition, EntitiesManager::queryEntity($entityRecord));
                        }
                        break;

                    // Email addresses can be treated as two-in-one, Username+Domain pair and Domain only
                    // Eg; the database could've blacklisted the entire domain or just a select few users from a domain
                    case NamedEntityType::EMAIL:
                        $emailParts = Utilities::parseEmail($entityPosition->getValue());
                        if($emailParts === null)
                        {
                            break;
                        }

                        $entityPairRecord = EntitiesManager::getEntityByHash(Utilities::hashEntity($emailParts['domain'], $emailParts['username']));
                        $entityDomainRecord = EntitiesManager::getEntityByHash(Utilities::hashEntity($emailParts['domain']));

                        if($entityPairRecord !== null)
                        {
                            $results[] = new NamedEntity($entityPosition, EntitiesManager::queryEntity($entityPairRecord));
                        }

                        if($entityDomainRecord !== null)
                        {
                            // Create a separate NamedEntityPosition for just the domain part
                            $emailValue = $entityPosition->getValue();
                            $atPosition = strpos($emailValue, '@');
                            if($atPosition !== false)
                            {
                                $domainOffset = $entityPosition->getOffset() + $atPosition + 1; // +1 to skip the '@'
                                $domainLength = strlen($emailParts['domain']);
                                $domainPosition = new NamedEntityPosition(
                                    NamedEntityType::DOMAIN, 
                                    $emailParts['domain'], 
                                    $domainOffset, 
                                    $domainLength
                                );
                                $results[] = new NamedEntity($domainPosition, EntitiesManager::queryEntity($entityDomainRecord));
                            }
                        }
                        break;

                    // URLs are arbitrary, depend only on the domain name
                    case NamedEntityType::URL:
                        $urlHost = Utilities::extractDomainFromUrl($entityPosition->getValue());
                        if($urlHost === null)
                        {
                            break;
                        }

                        $entityRecord = EntitiesManager::getEntityByHash(Utilities::hashEntity($urlHost));
                        if($entityRecord !== null)
                        {
                            $results[] = new NamedEntity($entityPosition, EntitiesManager::queryEntity($entityRecord));
                        }
                        break;
                }
            }

            return $results;
        }
        
        /**
         * Extract named entities from the given text
         * @param string $text The input text to extract entities from
         * @return NamedEntityPosition[] Array of extracted named entities
         */
        private static function extractEntities(string $text): array
        {
            if(empty($text))
            {
                return [];
            }

            $entities = [];
            $processedRanges = [];

            // Get all entity types sorted by priority (highest first)
            $entityTypes = self::getEntityTypesByPriority();

            foreach ($entityTypes as $entityType)
            {
                $matches = self::findMatches($text, $entityType);
                foreach ($matches as $match)
                {
                    $offset = $match['offset'];
                    $length = strlen($match['value']);
                    
                    // Skip if this range overlaps with a higher priority entity
                    if (self::hasOverlap($offset, $length, $processedRanges))
                    {
                        continue;
                    }
                    
                    // Validate the matched value
                    if (!$entityType->isValid($match['value']))
                    {
                        continue;
                    }
                    
                    $entities[] = new NamedEntityPosition($entityType, $match['value'], $offset, $length);
                    $processedRanges[] = ['offset' => $offset, 'length' => $length];
                }
            }

            // Sort entities by their position in the text
            usort($entities, function(NamedEntityPosition $a, NamedEntityPosition $b)
            {
                return $a->getOffset() <=> $b->getOffset();
            });

            return $entities;
        }

        /**
         * Get all entity types sorted by priority (highest first)
         * @return NamedEntityType[]
         */
        private static function getEntityTypesByPriority(): array
        {
            $entityTypes = [
                NamedEntityType::DOMAIN,
                NamedEntityType::URL,
                NamedEntityType::EMAIL,
                NamedEntityType::IPv4,
                NamedEntityType::IPv6,
            ];

            usort($entityTypes, function(NamedEntityType $a, NamedEntityType $b)
            {
                return $b->getPriority() <=> $a->getPriority();
            });

            return $entityTypes;
        }

        /**
         * Find all matches for a specific entity type in the text
         * @param string $text The text to search in
         * @param NamedEntityType $entityType The entity type to search for
         * @return array Array of matches with 'value' and 'offset' keys
         */
        private static function findMatches(string $text, NamedEntityType $entityType): array
        {
            $matches = [];
            $pattern = $entityType->getPattern();
            
            if (preg_match_all($pattern, $text, $pregMatches, PREG_OFFSET_CAPTURE))
            {
                foreach ($pregMatches[0] as $match)
                {
                    $value = trim($match[0]);
                    $offset = $match[1];
                    
                    // Skip empty matches or matches that are just whitespace
                    if (empty($value) || ctype_space($value))
                    {
                        continue;
                    }
                    
                    // Additional cleaning for specific types
                    $cleanValue = self::cleanMatch($value, $entityType);
                    if (empty($cleanValue))
                    {
                        continue;
                    }
                    
                    $matches[] = [
                        'value' => $cleanValue,
                        'offset' => $offset + (strlen($value) - strlen($cleanValue)) // Adjust offset if cleaning changed the value
                    ];
                }
            }

            return $matches;
        }

        /**
         * Clean a matched value based on the entity type
         * @param string $value The matched value
         * @param NamedEntityType $entityType The entity type
         * @return string The cleaned value
         */
        private static function cleanMatch(string $value, NamedEntityType $entityType): string
        {
            $value = trim($value);
            
            switch ($entityType)
            {
                case NamedEntityType::DOMAIN:
                    // Remove trailing dots and common punctuation
                    $value = rtrim($value, '.,;:!?');
                    break;
                    
                case NamedEntityType::URL:
                    // Remove trailing punctuation that's not part of the URL
                    $value = rtrim($value, '.,;:!?)');
                    break;
                    
                case NamedEntityType::EMAIL:
                    // Remove trailing punctuation
                    $value = rtrim($value, '.,;:!?)');
                    break;
                    
                case NamedEntityType::IPv4:
                case NamedEntityType::IPv6:
                    // IPs are generally clean, just trim
                    break;
            }
            
            return $value;
        }

        /**
         * Check if a range overlaps with any of the processed ranges
         * @param int $offset The starting position
         * @param int $length The length of the range
         * @param array $processedRanges Array of already processed ranges
         * @return bool True if there's an overlap
         */
        private static function hasOverlap(int $offset, int $length, array $processedRanges): bool
        {
            $end = $offset + $length;
            
            foreach ($processedRanges as $range)
            {
                $rangeStart = $range['offset'];
                $rangeEnd = $rangeStart + $range['length'];
                
                // Check for overlap: ranges overlap if one starts before the other ends
                if ($offset < $rangeEnd && $end > $rangeStart)
                {
                    return true;
                }
            }
            
            return false;
        }
    }