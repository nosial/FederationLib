<?php

    namespace TestPlugin;

    use FederationLib\Objects\EvidenceRecord;
    use FederationLib\Objects\Plugin\RecordChange;
    use FederationLib\Objects\ReportRecord;

    /**
     * Records the changes received by the RECORD_CHANGE event handler to a file so that the test units can
     * observe them through a request
     */
    class RecordChangeEvents
    {
        /**
         * Returns the path of the events file
         *
         * @return string The file path
         */
        public static function getPath(): string
        {
            return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'net.nosial.test_plugin.record_change_events.json';
        }

        /**
         * Reads the recorded events, oldest first
         *
         * @return array[] The recorded events
         */
        public static function read(): array
        {
            if(!is_file(self::getPath()))
            {
                return [];
            }

            $data = json_decode((string)file_get_contents(self::getPath()), true);
            return is_array($data) ? $data : [];
        }

        /**
         * Records a change, including the parts of the record the test units check
         *
         * @param RecordChange $change The change
         * @return void
         */
        public static function record(RecordChange $change): void
        {
            $record = $change->getRecord();
            $event = [
                'type' => $change->getType()->value,
                'record_type' => $change->getRecordType()->value,
                'uuid' => $change->getUuid(),
                'record_exists' => $record !== null,
            ];

            if($record instanceof EvidenceRecord)
            {
                $event['entity'] = $record->getEntityUuid();
                $event['text_content'] = $record->getTextContent();
                $event['classification'] = $record->getClassificationFlag()?->value;
            }
            elseif($record instanceof ReportRecord)
            {
                $event['opened'] = $record->isOpened();
                $event['assigned_operator'] = $record->getAssignedOperator();
            }

            $handle = fopen(self::getPath(), 'c+');
            if($handle === false)
            {
                return;
            }

            try
            {
                flock($handle, LOCK_EX);
                $events = json_decode((string)stream_get_contents($handle), true);
                if(!is_array($events))
                {
                    $events = [];
                }

                $events[] = $event;
                $events = array_slice($events, -500);

                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, json_encode($events));
                fflush($handle);
                flock($handle, LOCK_UN);
            }
            finally
            {
                fclose($handle);
            }
        }

        /**
         * Resets the recorded events
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
