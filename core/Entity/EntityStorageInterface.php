<?php

declare(strict_types=1);

namespace Framework\Entity;

interface EntityStorageInterface
{
    public function find(string $type, int $id, ?string $connection = null): ?object;

    public function save(object $entity): void;

    public function delete(object $entity): void;
}
