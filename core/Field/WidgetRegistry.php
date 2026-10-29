<?php

declare(strict_types=1);

namespace Framework\Field;

final class WidgetRegistry
{
    /** @var array<string, WidgetInterface> */
    private array $widgets = [];

    public function __construct()
    {
        foreach (['string', 'text', 'integer', 'bigint', 'decimal', 'float', 'boolean', 'date', 'datetime'] as $id) {
            $this->register(new ScalarWidget($id));
        }
    }

    public function register(WidgetInterface $widget): void
    {
        $id = $widget->id();
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $id) || isset($this->widgets[$id])) {
            throw new \InvalidArgumentException("Duplicate or invalid widget: {$id}");
        }
        $this->widgets[$id] = $widget;
    }

    public function get(string $id): WidgetInterface
    {
        return $this->widgets[$id] ?? throw new \InvalidArgumentException("Unknown widget: {$id}");
    }
}
