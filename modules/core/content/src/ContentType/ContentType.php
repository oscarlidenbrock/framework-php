<?php

declare(strict_types=1);

namespace Module\Content\ContentType;

use Framework\Entity\EntityTypeInterface;

final class ContentType implements EntityTypeInterface
{
    public function __construct(
        public readonly string $namespace,
        public readonly string $label,
        public readonly string $description,
        public readonly ?string $module,
        public readonly string $connection,
        public readonly string $schemaStatus,
    ) {
    }

    public function id(): string
    {
        return $this->namespace;
    }

    public function connectionName(): string
    {
        return $this->connection;
    }
}
