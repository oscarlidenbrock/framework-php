<?php

declare(strict_types=1);

namespace Framework\Log;

use Framework\Runtime\RuntimePaths;

final readonly class LogReader
{
    public function __construct(private RuntimePaths $paths)
    {
    }

    /** @return list<array{id: string, time: string, channel: string, level: string, message: string, context: array<string, mixed>}> */
    public function recent(?string $channel = null, ?string $level = null, int $limit = 50): array
    {
        if ($channel !== null && !preg_match('/^[a-z][a-z0-9_]*$/', $channel) || $level !== null && !preg_match('/^[a-z]+$/', $level) || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Invalid log filter.');
        }
        $directory = $this->paths->logs();
        if (!is_dir($directory)) {
            return [];
        }
        $names = @scandir($directory);
        if ($names === false) {
            return [];
        }
        $files = array_values(array_filter($names, static fn (string $name): bool => preg_match('/^[a-z][a-z0-9_]*\.log(?:\.[1-9][0-9]?)?$/', $name) === 1 && ($channel === null || str_starts_with($name, $channel . '.log'))));
        usort($files, static fn (string $a, string $b): int => (@filemtime($directory . '/' . $b) ?: 0) <=> (@filemtime($directory . '/' . $a) ?: 0));
        $records = [];
        foreach (array_slice($files, 0, 32) as $name) {
            $path = $directory . '/' . $name;
            if (is_link($path) || !is_file($path)) {
                continue;
            }
            $size = @filesize($path);
            $file = @fopen($path, 'rb');
            if ($size === false || $file === false) {
                continue;
            }
            $read = min($size, 65536);
            fseek($file, -$read, SEEK_END);
            $data = @fread($file, $read);
            fclose($file);
            if ($data === false) {
                continue;
            }
            $lines = explode("\n", $data);
            if ($read < $size) {
                array_shift($lines);
            }
            foreach (array_slice($lines, -200) as $line) {
                if ($line === '' || strlen($line) > 8192) {
                    continue;
                }
                $record = json_decode($line, true);
                if (!is_array($record) || !is_string($record['time'] ?? null) || !is_string($record['channel'] ?? null) || !is_string($record['level'] ?? null) || !is_string($record['message'] ?? null) || !is_array($record['context'] ?? null) || $level !== null && $record['level'] !== $level) {
                    continue;
                }
                $records[] = ['id' => hash('sha256', $name . $line), 'time' => $record['time'], 'channel' => $record['channel'], 'level' => $record['level'], 'message' => $record['message'], 'context' => $record['context']];
            }
        }
        usort($records, static fn (array $a, array $b): int => strcmp($b['time'], $a['time']));
        return array_slice($records, 0, $limit);
    }

    /** @return array{id: string, time: string, channel: string, level: string, message: string, context: array<string, mixed>}|null */
    public function detail(string $id): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $id)) {
            throw new \InvalidArgumentException('Invalid log entry ID.');
        }
        foreach ($this->recent(limit: 100) as $record) {
            if ($record['id'] === $id) {
                return $record;
            }
        }
        return null;
    }
}
