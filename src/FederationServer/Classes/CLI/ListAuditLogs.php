<?php

    namespace FederationServer\Classes\CLI;

    use FederationServer\Classes\Managers\AuditLogManager;
    use FederationServer\Classes\Enums\AuditLogType;
    use FederationServer\Interfaces\CommandLineInterface;
    use FederationServer\Objects\AuditLogRecord;
    use InvalidArgumentException;
    use Throwable;

    class ListAuditLogs implements CommandLineInterface
    {
        /**
         * @inheritDoc
         */
        public static function handle(array $args): int
        {
            $limit = 10;
            $page = 1;
            $filterType = null;

            foreach ($args as $i => $arg)
            {
                if ($arg === '--limit' && isset($args[$i+1]))
                {
                    $limit = (int)$args[$i+1];
                }
                elseif ($arg === '--page' && isset($args[$i+1]))
                {
                    $page = (int)$args[$i+1];
                }
                elseif ($arg === '--type' && isset($args[$i+1]))
                {
                    $types = explode(',', $args[$i+1]);
                    $filterType = [];
                    foreach ($types as $type)
                    {
                        $type = trim($type);
                        if (defined(AuditLogType::class . '::' . $type))
                        {
                            $filterType[] = constant(AuditLogType::class . '::' . $type);
                        }
                    }
                }
            }

            try
            {
                $entries = AuditLogManager::getEntries($limit, $page, $filterType);

                if (empty($entries))
                {
                    print("No audit log entries found.\n");
                    return 0;
                }

                foreach ($entries as $entry)
                {
                    /** @var AuditLogRecord $entry */
                    printf("[%s] %s | Operator: %s | Entity: %s | %s\n",
                        $entry->getTimestamp() ?? '-',
                        $entry->getType()->value ?? '-',
                        $entry->getOperator() ?? '-',
                        $entry->getEntity() ?? '-',
                        $entry->getMessage() ?? '-'
                    );
                }
            }
            catch (InvalidArgumentException $e)
            {
                print("Error: " . $e->getMessage() . "\n");
                return 1;
            }
            catch (Throwable $e)
            {
                print("Unexpected error: " . $e->getMessage() . "\n");
                return 2;
            }

            return 0;
        }

        /**
         * @inheritDoc
         */
        public static function getHelp(): string
        {
            return "Usage:\n" .
                   "  federationserver list-audit [--limit <n>] [--page <n>] [--type <type>]\n" .
                   "\nDescription:\n" .
                   "  Lists audit log entries.\n" .
                   "\nOptions:\n" .
                   "  --limit <n>    Number of entries to show (default: 10)\n" .
                   "  --page <n>     Page number (default: 1)\n" .
                   "  --type <type>  Filter by audit log type (optional, comma-separated)\n";
        }

        /**
         * @inheritDoc
         */
        public static function getShortHelp(): string
        {
            return "Lists audit log entries.";
        }

        /**
         * @inheritDoc
         */
        public static function getExamples(): ?string
        {
            return "Examples:\n" .
                   "  federationserver list-audit --limit 5 --type OPERATOR_CREATE,OPERATOR_DELETE\n";
        }
    }

