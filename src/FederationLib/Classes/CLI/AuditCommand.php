<?php

    namespace FederationLib\Classes\CLI;

    use FederationLib\Classes\Logger;
    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Interfaces\CommandLineInterface;
    use InvalidArgumentException;

    class AuditCommand implements CommandLineInterface
    {
        /**
         * @inheritDoc
         */
        public static function handle(array $tokens): int
        {
            $arguments = Arguments::parse($tokens, ['page', 'limit', 'type']);
            if(!in_array($arguments->get(0), ['ls', 'list'], true) || $arguments->count() > 1)
            {
                print(self::getHelp());
                return $arguments->wantsHelp() ? 0 : 1;
            }

            try
            {
                $limit = $arguments->positiveInt('limit', 10);
                $page = $arguments->positiveInt('page', 1);

                $types = null;
                if($arguments->has('type'))
                {
                    $types = [];
                    foreach(explode(',', (string)$arguments->option('type')) as $value)
                    {
                        $type = AuditLogType::tryFrom(strtoupper(trim($value)));
                        if($type === null)
                        {
                            throw new InvalidArgumentException(sprintf("Unknown audit log type '%s'", trim($value)));
                        }

                        $types[] = $type;
                    }
                }

                $entries = AuditLogManager::getEntries($limit, $page, $types);
            }
            catch(InvalidArgumentException $e)
            {
                print(sprintf("Error: %s\n", $e->getMessage()));
                return 1;
            }
            catch(DatabaseOperationException $e)
            {
                Logger::log()->error('Failed to list the audit log: ' . $e->getMessage(), $e);
                print("Error: Failed to list the audit log, see the logs for details.\n");
                return 1;
            }

            if(empty($entries))
            {
                print("No audit log entries found.\n");
                return 0;
            }

            foreach($entries as $entry)
            {
                printf("[%s] %s | Operator: %s | Entity: %s | %s\n",
                    date('Y-m-d H:i:s', $entry->getTimestamp()),
                    $entry->getType()->value,
                    $entry->getOperatorUuid() ?? '-',
                    $entry->getEntityUuid() ?? '-',
                    $entry->getMessage()
                );
            }

            return 0;
        }

        /**
         * @inheritDoc
         */
        public static function getHelp(): string
        {
            return "Usage:\n" .
                "  federationlib audit ls [--limit <n>] [--page <n>] [--type <type>[,<type>...]]\n" .
                "\nCommands:\n" .
                "  ls  List audit log entries, newest first\n" .
                "\nOptions:\n" .
                "  --limit <n>    Number of entries to show (default: 10)\n" .
                "  --page <n>     Page number (default: 1)\n" .
                "  --type <type>  Only entries of these audit log types, comma-separated\n";
        }

        /**
         * @inheritDoc
         */
        public static function getShortHelp(): string
        {
            return "Reads the audit log: ls.";
        }

        /**
         * @inheritDoc
         */
        public static function getExamples(): ?string
        {
            return "Examples:\n" .
                "  federationlib audit ls\n" .
                "  federationlib audit ls --limit 5 --type OPERATOR_CREATED,OPERATOR_DELETED\n";
        }
    }
