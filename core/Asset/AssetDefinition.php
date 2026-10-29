<?php

declare(strict_types=1);

namespace Framework\Asset;

final readonly class AssetDefinition
{
    /** @param array<string, string|int|bool> $attributes */
    public function __construct(
        public string $type,
        public string $path,
        public string $position,
        public array $attributes,
        public ?string $url = null,
    ) {
    }

    public function withUrl(string $url): self
    {
        return new self($this->type, $this->path, $this->position, $this->attributes, $url);
    }

    public function isExternal(): bool
    {
        return strncasecmp($this->path, 'https://', 8) === 0;
    }

    /** @return array{type: string, position: string, attributes: array<string, string|int|bool>} */
    public function configuration(): array
    {
        return ['type' => $this->type, 'position' => $this->position, 'attributes' => $this->attributes];
    }
}
