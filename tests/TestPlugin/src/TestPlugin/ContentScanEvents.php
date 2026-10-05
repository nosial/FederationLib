<?php

    namespace TestPlugin;

    use FederationLib\Objects\Plugin\ContentScan;

    /**
     * Records what the CONTENT_SCAN event handler received for the most recent inspected scan to a file so that the
     * test units can verify it through a request
     */
    class ContentScanEvents
    {
        /**
         * Returns the path of the events file
         *
         * @return string The file path
         */
        public static function getPath(): string
        {
            return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'net.nosial.test_plugin.content_scan_events.json';
        }

        /**
         * Reads the most recent inspected scan
         *
         * @return array|null The inspected scan, null if no scan was inspected
         */
        public static function read(): ?array
        {
            if(!is_file(self::getPath()))
            {
                return null;
            }

            $data = json_decode((string)file_get_contents(self::getPath()), true);
            return is_array($data) ? $data : null;
        }

        /**
         * Records what the event handler received for a scan
         *
         * @param ContentScan $contentScan The content scan
         * @return void
         */
        public static function record(ContentScan $contentScan): void
        {
            file_put_contents(self::getPath(), json_encode([
                'evidence' => array_map(fn($evidence) => [
                    'text_content' => $evidence->getTextContent(),
                    'note' => $evidence->getNote(),
                    'tag' => $evidence->getTag(),
                    'confidential' => $evidence->isConfidential(),
                    'metadata' => $evidence->getMetadata(),
                ], $contentScan->getEvidence()),
                'text_contents' => $contentScan->getTextContents(),
                'author_identifier' => $contentScan->getAuthorIdentifier(),
                'author_entity' => $contentScan->getAuthorEntity()?->getEntity()->getUuid(),
                'resolved_entities' => array_map(fn($resolvedEntity) => $resolvedEntity->getEntity()->getUuid(), $contentScan->getResolvedEntities()),
                'authenticated_operator' => $contentScan->getAuthenticatedOperator()?->getUuid(),
                'top_k' => $contentScan->getTopK(),
                'threshold' => $contentScan->getThreshold(),
            ]), LOCK_EX);
        }

        /**
         * Resets the recorded scan
         *
         * @return void
         */
        public static function reset(): void
        {
            if(is_file(self::getPath()))
            {
                @unlink(self::getPath());
            }
        }
    }
