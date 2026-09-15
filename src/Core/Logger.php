<?php

declare(strict_types=1);

namespace Zorvex\Core;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use RuntimeException;

/**
 * PSR-3 compatible file logger with severity levels and context interpolation.
 *
 * Writes structured lines to `storage/logs/app.log` and emits the same records
 * via the shared EventBus when the container is available.
 *
 * @package Zorvex\Core
 */
final class Logger extends AbstractLogger
{
    private const LEVELS = [
        LogLevel::EMERGENCY => 0,
        LogLevel::ALERT     => 1,
        LogLevel::CRITICAL  => 2,
        LogLevel::ERROR     => 3,
        LogLevel::WARNING   => 4,
        LogLevel::NOTICE    => 5,
        LogLevel::INFO      => 6,
        LogLevel::DEBUG     => 7,
    ];

    private readonly string $file;

    private readonly int $threshold;

    private ?EventBus $eventBus = null;

    private int $writes = 0;

    public function __construct(string $file = '', string $level = 'debug')
    {
        $this->file = $file !== '' ? $file : (string) (config('log.path') ?? __DIR__ . '/../../storage/logs/app.log');
        $this->threshold = self::LEVELS[$level] ?? self::LEVELS[LogLevel::DEBUG];

        $directory = dirname($this->file);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create log directory [{$directory}].");
        }

        if (!is_writable($directory)) {
            throw new RuntimeException("Log directory [{$directory}] is not writable.");
        }
    }

    /**
     * Attach an event bus so every record can be observed globally.
     */
    public function attachBus(EventBus $bus): void
    {
        $this->eventBus = $bus;
    }

    /**
     * @inheritDoc
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (($this->threshold < (self::LEVELS[$level] ?? self::LEVELS[LogLevel::INFO]))) {
            return;
        }

        $line = sprintf(
            "[%s] %s.%s %s\n",
            gmdate('Y-m-d H:i:s'),
            strtoupper($level),
            self::caller(),
            $this->interpolate((string) $message, $context)
        );

        // Honors open_basedir etc.; explicit error check makes failures visible.
        $written = @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);

        if ($written === false) {
            error_log("[Zorvex][LOG-FAILURE] Could not write to {$this->file}");
        }

        $this->writes++;

        if ($this->eventBus !== null) {
            $context['level'] = $level;
            $context['message'] = (string) $message;
            $this->eventBus->dispatch('log', $context);
        }
    }

    /**
     * Replace `{placeholder}` tokens inside the message with context values.
     */
    private function interpolate(string $message, array $context): string
    {
        if ($context === []) {
            return $message;
        }

        return (string) preg_replace_callback('/\{([a-zA-Z0-9_.]+)\}/', static function (array $matches) use ($context) {
            $key = $matches[1];

            if (array_key_exists($key, $context)) {
                $value = $context[$key];

                if (is_scalar($value) || $value === null) {
                    return (string) $value;
                }

                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: var_export($value, true);
            }

            return $matches[0];
        }, $message);
    }

    /**
     * Identify the file:line of the log call for debugging.
     */
    private static function caller(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';
            if (str_contains($file, 'src' . DIRECTORY_SEPARATOR)) {
                $line = $frame['line'] ?? 0;
                $name = basename($file);

                return "{$name}:{$line}";
            }
        }

        return 'unknown';
    }

    /**
     * Number of records written since construction (helpful for stats).
     */
    public function countWrites(): int
    {
        return $this->writes;
    }
}