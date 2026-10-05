<?php

    namespace FederationLib\Objects\Plugin;

    use FederationLib\Enums\EventType;
    use FederationLib\Exceptions\PluginException;

    class EventHandlerDefinition
    {
        private EventType $event;
        private string $class;
        /** @var string[] */
        private array $filter;

        /**
         * EventHandlerDefinition constructor.
         *
         * @param EventType $event The event the handler is executed for
         * @param string $class The fully qualified class name of the event handler
         * @param string[] $filter The values the handler is executed for (eg; audit log types), empty for every event
         * @throws PluginException If any of the given values are invalid
         */
        public function __construct(EventType $event, string $class, array $filter=[])
        {
            // Normalize the class name, allowing the forms `\Foo\Bar`, `Foo\Bar` and `\Foo\Bar::class`
            $class = trim($class);
            if(str_ends_with($class, '::class'))
            {
                $class = substr($class, 0, -strlen('::class'));
            }

            $class = ltrim($class, '\\');
            if($class === '')
            {
                throw new PluginException(sprintf('The %s event handler has no class', $event->value));
            }

            if(count($filter) > 0 && count($event->getFilterValues()) === 0)
            {
                throw new PluginException(sprintf('The %s event handler "%s" has a filter, but the %s event does not support filters', $event->value, $class, $event->value));
            }

            $normalizedFilter = [];
            foreach($filter as $value)
            {
                if(!is_string($value))
                {
                    throw new PluginException(sprintf('The %s event handler "%s" has an invalid filter value', $event->value, $class));
                }

                $value = strtoupper(trim($value));
                if(!in_array($value, $event->getFilterValues(), true))
                {
                    throw new PluginException(sprintf('The %s event handler "%s" has an unknown filter value "%s", expected any of: %s', $event->value, $class, $value, implode(', ', $event->getFilterValues())));
                }

                $normalizedFilter[$value] = $value;
            }

            $this->event = $event;
            $this->class = $class;
            $this->filter = array_values($normalizedFilter);
        }

        /**
         * Constructs an EventHandlerDefinition from a plugin's package options, eg;
         *
         *  event: AUDIT_LOG
         *  class: \TestPlugin\EventHandlers\AuditLogHandler::class
         *  filter: OPERATOR_CREATED, OPERATOR_DELETED
         *
         * @param array $data The event handler definition
         * @return EventHandlerDefinition The parsed definition
         * @throws PluginException If the definition is invalid
         */
        public static function fromArray(array $data): EventHandlerDefinition
        {
            if(!isset($data['event']) || !is_string($data['event']))
            {
                throw new PluginException('An event handler is missing the required "event" property');
            }

            $event = EventType::tryFromCaseInsensitive($data['event']);
            if($event === null)
            {
                throw new PluginException(sprintf('An event handler has an unknown event "%s", expected one of: %s', $data['event'], implode(', ', array_map(fn(EventType $type) => $type->value, EventType::cases()))));
            }

            if(!isset($data['class']) || !is_string($data['class']))
            {
                throw new PluginException(sprintf('The %s event handler is missing the required "class" property', $event->value));
            }

            $filter = $data['filter'] ?? [];
            if(is_string($filter))
            {
                $filter = array_filter(array_map('trim', explode(',', $filter)), fn(string $value) => $value !== '');
            }

            if(!is_array($filter))
            {
                throw new PluginException(sprintf('The %s event handler "%s" has an invalid filter, expected a list of values', $event->value, $data['class']));
            }

            return new EventHandlerDefinition($event, $data['class'], array_values($filter));
        }

        /**
         * Returns the event the handler is executed for
         *
         * @return EventType The event
         */
        public function getEvent(): EventType
        {
            return $this->event;
        }

        /**
         * Returns the fully qualified class name of the event handler
         *
         * @return string The class name, eg; `TestPlugin\EventHandlers\AuditLogHandler`
         */
        public function getClass(): string
        {
            return $this->class;
        }

        /**
         * Returns the values the handler is executed for
         *
         * @return string[] The filter values, empty if the handler is executed for every event
         */
        public function getFilter(): array
        {
            return $this->filter;
        }

        /**
         * Returns True if the handler is executed for an event with the given value (eg; an audit log type)
         *
         * @param string $value The event's value
         * @return bool True if the handler should be executed
         */
        public function matches(string $value): bool
        {
            return count($this->filter) === 0 || in_array($value, $this->filter, true);
        }

        /**
         * Validates that the event handler class exists and implements the event's handler interface, this requires
         * the plugin's package to be imported
         *
         * @return void
         * @throws PluginException If the class does not exist or does not implement the event's handler interface
         */
        public function validateClass(): void
        {
            if(!class_exists($this->class))
            {
                throw new PluginException(sprintf('The %s event handler class "%s" does not exist', $this->event->value, $this->class));
            }

            if(!is_subclass_of($this->class, $this->event->getHandlerInterface()))
            {
                throw new PluginException(sprintf('The %s event handler class "%s" must implement %s', $this->event->value, $this->class, $this->event->getHandlerInterface()));
            }
        }
    }
