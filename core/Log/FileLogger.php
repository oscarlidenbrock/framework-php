<?php

declare(strict_types=1);

namespace Framework\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

final class FileLogger extends AbstractLogger
{
    public function __construct(private readonly string $path, private readonly string $channel, private readonly int $maxBytes, private readonly int $retention)
    {
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (!in_array($level, [LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR, LogLevel::WARNING, LogLevel::NOTICE, LogLevel::INFO, LogLevel::DEBUG], true)) {
            throw new \InvalidArgumentException('Invalid log level.');
        }
        try {
            $record = ['time' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'), 'channel' => $this->channel, 'level' => $level, 'message' => mb_substr((string) $message, 0, 1000), 'context' => $this->clean($context)];
            $directory = dirname($this->path);
            if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
                return;
            }
            if (is_link($this->path) || is_link($this->path . '.lock')) {
                return;
            }
            $lock = @fopen($this->path . '.lock', 'c');
            if ($lock === false) {
                return;
            }
            try {
                if (!@flock($lock, LOCK_EX)) {
                    return;
                }
                clearstatcache(true, $this->path);
                $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
                if (is_file($this->path) && @filesize($this->path) + strlen($line) > $this->maxBytes) {
                    $this->rotate();
                }
                $file = @fopen($this->path, 'ab');
                if ($file !== false) {
                    $remaining = $line;
                    while ($remaining !== '') {
                        $written = @fwrite($file, $remaining);
                        if ($written === false || $written === 0) {
                            break;
                        }
                        $remaining = substr($remaining, $written);
                    }
                    fclose($file);
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } catch (\Throwable) {
            // Logging failures are deliberately dropped to avoid recursive error handling.
        }
    }

    private function rotate(): void
    {
        for ($index = $this->retention; $index >= 1; --$index) {
            $target = $this->path . '.' . $index;
            if ($index === $this->retention && is_file($target)) {
                @unlink($target);
            }
            $source = $index === 1 ? $this->path : $this->path . '.' . ($index - 1);
            if (is_file($source)) {
                @rename($source, $target);
            }
        }
    }

    /** @param array<string|int, mixed> $context
     *  @return array<string|int, mixed>
     */
    private function clean(array $context, int $depth = 0): array
    {
        if ($depth > 3) {
            return ['truncated' => true];
        }
        $safe = [];
        foreach (array_slice($context, 0, 32, true) as $key => $value) {
            if (is_string($key) && preg_match('/password|cookie|csrf|token|secret|credential|authorization|request_body/i', $key)) {
                $safe[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $safe[$key] = $this->clean($value, $depth + 1);
            } elseif (is_string($value)) {
                $safe[$key] = mb_substr($value, 0, 500);
            } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $safe[$key] = $value;
            } else {
                $safe[$key] = '[object]';
            }
        }
        return $safe;
    }
}
