<?php

declare(strict_types=1);

namespace Zorvex\Core;

/**
 * Minimal synchronous event dispatcher.
 *
 * Events are dispatched to listeners keyed by a string name. A listener may
 * return false to halt propagation of the event to remaining listeners.
 *
 * @package Zorvex\Core
 */
final class EventBus
{
    /** @var array<string, list<callable>> */
    private array $listeners = [];

    /**
     * Subscribe a listener for an event.
     */
    public function listen(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /**
     * Dispatch an event to all registered listeners.
     *
     * Returns true if no listener halted propagation.
     */
    public function dispatch(string $event, mixed $payload = null): bool
    {
        if (empty($this->listeners[$event])) {
            return true;
        }

        foreach ($this->listeners[$event] as $listener) {
            $result = $listener($payload, $event);

            if ($result === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Remove all listeners for an event (or all listeners).
     */
    public function forget(?string $event = null): void
    {
        if ($event === null) {
            $this->listeners = [];
            return;
        }

        unset($this->listeners[$event]);
    }

    /**
     * List of registered event names.
     *
     * @return list<string>
     */
    public function events(): array
    {
        return array_keys($this->listeners);
    }
}