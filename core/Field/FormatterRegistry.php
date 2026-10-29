<?php

declare(strict_types=1);

namespace Framework\Field;

final class FormatterRegistry
{
    /** @var array<string, FormatterInterface> */
    private array $formatters = [];

    public function __construct()
    {
        foreach (['string', 'text', 'integer', 'bigint', 'decimal', 'float', 'boolean', 'date', 'datetime'] as $id) {
            $this->register(new ScalarFormatter($id));
        }
    }

    public function register(FormatterInterface $formatter): void
    {
        $id = $formatter->id();
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $id) || isset($this->formatters[$id])) {
            throw new \InvalidArgumentException("Duplicate or invalid formatter: {$id}");
        }
        $this->formatters[$id] = $formatter;
    }

    public function get(string $id): FormatterInterface
    {
        return $this->formatters[$id] ?? throw new \InvalidArgumentException("Unknown formatter: {$id}");
    }
}
