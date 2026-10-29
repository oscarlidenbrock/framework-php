<?php

declare(strict_types=1);

namespace Module\Block;

use Framework\Block\BlockRegistry;
use Framework\Database\DatabaseRegistry;
use Framework\Security\RequestSecurityInterface;
use Framework\Theme\ThemeManager;

final class PlacementManager
{
    public function __construct(
        private readonly DatabaseRegistry $databases,
        private readonly ThemeManager $themes,
        private readonly BlockRegistry $blocks,
        private readonly string $workspace,
        private readonly ?RequestSecurityInterface $security = null,
    ) {
    }

    /** @return list<array{block: string, region: string, weight: int, enabled: bool}> */
    public function placements(string $theme): array
    {
        if ($this->databases->defaultName() === null) {
            return [];
        }
        $database = $this->databases->manager();
        if (!$database->connection()->createSchemaManager()->tablesExist(['config'])) {
            return [];
        }
        $row = $database->queryOne('SELECT value FROM config WHERE workspace = :workspace AND setting_key = :key', ['workspace' => $this->workspace, 'key' => 'blocks.placements.' . $theme]);
        if ($row === null) {
            return [];
        }
        $data = json_decode((string) $row['value'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !array_is_list($data)) {
            throw new \UnexpectedValueException('Invalid stored block placements.');
        }
        foreach ($data as $placement) {
            if (!is_array($placement) || !is_string($placement['block'] ?? null) || !is_string($placement['region'] ?? null) || !is_int($placement['weight'] ?? null) || !is_bool($placement['enabled'] ?? null)) {
                throw new \UnexpectedValueException('Invalid stored block placement.');
            }
        }
        return $data;
    }

    /** @param list<array<string, mixed>> $placements */
    public function save(string $theme, array $placements): void
    {
        if ($this->security?->active()) {
            $this->security->requirePermission('block.placements.edit');
        }
        $regions = $this->themes->regions($theme);
        $seen = [];
        foreach ($placements as $placement) {
            if (!is_string($placement['block'] ?? null) || $this->blocks->get($placement['block']) === null || isset($seen[$placement['block']]) || !is_string($placement['region'] ?? null) || !array_key_exists($placement['region'], $regions) || !is_int($placement['weight'] ?? null) || !is_bool($placement['enabled'] ?? null)) {
                throw new \InvalidArgumentException('Invalid block placement or unavailable block/region.');
            }
            $seen[$placement['block']] = true;
        }
        $database = $this->databases->manager();
        if (!$database->connection()->createSchemaManager()->tablesExist(['config'])) {
            throw new \LogicException('Configuration migration is pending.');
        }
        $key = 'blocks.placements.' . $theme;
        $database->transaction(function ($database) use ($key, $placements): void {
            $parameters = ['workspace' => $this->workspace, 'key' => $key, 'value' => json_encode($placements, JSON_THROW_ON_ERROR), 'updated' => gmdate('Y-m-d H:i:s.u')];
            $existing = $database->queryOne('SELECT 1 FROM config WHERE workspace = :workspace AND setting_key = :key', ['workspace' => $this->workspace, 'key' => $key]);
            if ($existing === null) {
                $database->execute('INSERT INTO config (workspace, setting_key, value, updated_at) VALUES (:workspace, :key, :value, :updated)', $parameters);
            } else {
                $database->execute('UPDATE config SET value = :value, updated_at = :updated WHERE workspace = :workspace AND setting_key = :key', $parameters);
            }
        });
    }
}
