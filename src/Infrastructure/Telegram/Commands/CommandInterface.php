<?php

declare(strict_types=1);

namespace Zorvex\Infrastructure\Telegram\Commands;

/**
 * Marker interface implemented by all bot commands.
 *
 * @package Zorvex\Infrastructure\Telegram\Commands
 */
interface CommandInterface
{
    /**
     * The command name slug (without leading slash).
     */
    public function name(): string;

    /**
     * Handle the command with the resolved update payload.
     *
     * @param array<string, mixed> $update The Telegram update array.
     * @return void
     */
    public function handle(array $update): void;
}