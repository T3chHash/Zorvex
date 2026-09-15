<?php

declare(strict_types=1);

namespace Zorvex\Domain\Entity;

/**
 * Domain statuses shared across entities.
 *
 * @package Zorvex\Domain\Entity
 */
enum Status: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Active = 'active';
    case Expired = 'expired';
    case Suspended = 'suspended';
    case Banned = 'banned';
    case Disabled = 'disabled';

    /**
     * Whether this status counts as a terminal success for payments.
     */
    public function isTerminalSuccess(): bool
    {
        return $this === self::Completed || $this === self::Active;
    }
}