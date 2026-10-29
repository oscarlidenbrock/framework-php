<?php

declare(strict_types=1);

namespace Module\Site;

use Framework\Asset\LibraryRegistry;
use Framework\Asset\LibraryResolver;
use Framework\Config\ConfigManager;
use Framework\Config\InstallationSnapshot;
use Framework\Database\DatabaseManager;
use Framework\Database\DatabaseRegistry;
use Framework\Module\ModuleManager;
use Framework\Security\RequestSecurityInterface;
use Framework\Theme\ThemeManager;

final class InstallationManager
{
    /** @param array{enabled_modules: list<string>, active_theme: string, admin_theme: string} $selection */
    public function __construct(
        private readonly string $rootPath,
        private readonly string $workspace,
        private array $selection,
        private readonly ConfigManager $configuration,
        private readonly DatabaseRegistry $databases,
        private readonly ModuleManager $modules,
        private readonly ThemeManager $themes,
        private readonly ?RequestSecurityInterface $security = null,
    ) {
    }

    /** @return array{enabled_modules: list<string>, active_theme: string, admin_theme: string} */
    public function current(): array
    {
        return $this->selection;
    }

    /** @return array<string, array{enabled: bool, requires: list<string>}> */
    public function modules(): array
    {
        $this->requirePermission('site.extensions.view');
        $result = [];
        foreach ($this->modules->discover() as $module) {
            $result[$module->id] = ['enabled' => in_array($module->id, $this->selection['enabled_modules'], true), 'requires' => $module->requires];
        }
        return $result;
    }

    /** @return list<string> */
    public function themes(): array
    {
        $this->requirePermission('site.themes.view');
        return array_keys($this->themes->discover());
    }

    public function enable(string $id): void
    {
        $this->requirePermission('site.extensions.edit');
        if (!isset($this->modules->discover()[$id])) {
            throw new \InvalidArgumentException("Module not found: {$id}");
        }
        if (in_array($id, $this->selection['enabled_modules'], true)) {
            return;
        }
        $candidate = $this->selection;
        $candidate['enabled_modules'][] = $id;
        $this->persist($candidate, true);
    }

    public function disable(string $id): void
    {
        $this->requirePermission('site.extensions.edit');
        if (in_array($id, ['site', 'admin'], true)) {
            throw new \InvalidArgumentException('The site and admin modules are required for administration.');
        }
        if (!in_array($id, $this->selection['enabled_modules'], true)) {
            throw new \InvalidArgumentException("Module is not enabled: {$id}");
        }
        $candidate = $this->selection;
        $candidate['enabled_modules'] = array_values(array_filter($candidate['enabled_modules'], static fn (string $enabled): bool => $enabled !== $id));
        $this->persist($candidate, false);
    }

    public function selectTheme(string $kind, string $id): void
    {
        $this->requirePermission('site.themes.edit');
        $key = match ($kind) {
            'public' => 'active_theme',
            'admin' => 'admin_theme',
            default => throw new \InvalidArgumentException('Invalid theme selection kind.'),
        };
        $candidate = $this->selection;
        $candidate[$key] = $id;
        $this->persist($candidate, false);
    }

    /** Rebuild the snapshot after an out-of-band repair to the persisted rows. */
    public function refresh(): void
    {
        $this->requireCli();
        $rows = $this->rows();
        if ($rows === []) {
            throw new \LogicException('No persisted installation selection exists.');
        }
        if (count($rows) !== 3 || !isset($rows['installation.enabled_modules'], $rows['installation.active_theme'], $rows['installation.admin_theme'])) {
            throw new \LogicException('Persisted installation selection is incomplete.');
        }
        try {
            $modules = json_decode($rows['installation.enabled_modules'], true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \LogicException('Invalid persisted module selection.', previous: $exception);
        }
        $candidate = ['enabled_modules' => $modules, 'active_theme' => $rows['installation.active_theme'], 'admin_theme' => $rows['installation.admin_theme']];
        $this->validate($candidate);
        (new InstallationSnapshot($this->rootPath, $this->workspace))->write($candidate);
        $this->selection = $candidate;
    }

    /** Explicitly replace persisted selection with the effective workspace YAML seed. */
    public function resetToSeed(): void
    {
        $this->requireCli();
        $seed = (new ConfigManager($this->rootPath . '/config', $this->workspace))->load();
        $candidate = [
            'enabled_modules' => $seed['enabled_modules'],
            'active_theme' => $seed['active_theme'],
            'admin_theme' => $seed['admin_theme'],
        ];
        $this->persist($candidate, false, true);
    }

    /** Explicit CLI preparation; it never runs during an HTTP request. */
    public function migrateFor(string $id): int
    {
        $this->requireCli();
        if (!isset($this->modules->discover()[$id])) {
            throw new \InvalidArgumentException("Module not found: {$id}");
        }
        $candidate = $this->selection;
        if (!in_array($id, $candidate['enabled_modules'], true)) {
            $candidate['enabled_modules'][] = $id;
        }
        $enabled = $this->validate($candidate);
        $registry = $this->candidateDatabases($enabled);
        $applied = 0;
        foreach ($registry->all() as $manager) {
            $applied += $manager->migrate();
        }
        return $applied;
    }

    /** @param array{enabled_modules: list<string>, active_theme: string, admin_theme: string} $candidate */
    private function persist(array $candidate, bool $checkMigrations, bool $replaceStale = false): void
    {
        $enabled = $this->validate($candidate);
        $database = $this->database();
        if (!$database->connection()->createSchemaManager()->tablesExist(['config'])) {
            throw new \LogicException('Site configuration migration is pending; run database:migrate first.');
        }
        if ($checkMigrations) {
            foreach ($this->candidateDatabases($enabled)->all() as $manager) {
                if ($manager->pendingMigrations() !== []) {
                    throw new \LogicException('Module migrations are pending; run module:migrate with --yes before enabling.');
                }
            }
        }
        $stored = $this->rows();
        if (!$replaceStale && $stored !== [] && (
            ($stored['installation.enabled_modules'] ?? null) !== json_encode($this->selection['enabled_modules'], JSON_THROW_ON_ERROR)
            || ($stored['installation.active_theme'] ?? null) !== $this->selection['active_theme']
            || ($stored['installation.admin_theme'] ?? null) !== $this->selection['admin_theme']
        )) {
            throw new \LogicException('Installation selection changed in the database; refresh it before editing.');
        }
        $values = [
            'installation.enabled_modules' => json_encode($candidate['enabled_modules'], JSON_THROW_ON_ERROR),
            'installation.active_theme' => $candidate['active_theme'],
            'installation.admin_theme' => $candidate['admin_theme'],
        ];
        $database->transaction(function (DatabaseManager $database) use ($values): void {
            foreach ($values as $key => $value) {
                $parameters = ['workspace' => $this->workspace, 'key' => $key, 'value' => $value, 'updated' => gmdate('Y-m-d H:i:s.u')];
                if ($database->queryOne('SELECT 1 FROM config WHERE workspace = :workspace AND setting_key = :key', ['workspace' => $this->workspace, 'key' => $key]) === null) {
                    $database->execute('INSERT INTO config (workspace, setting_key, value, updated_at) VALUES (:workspace, :key, :value, :updated)', $parameters);
                } else {
                    $database->execute('UPDATE config SET value = :value, updated_at = :updated WHERE workspace = :workspace AND setting_key = :key', $parameters);
                }
            }
        });
        (new InstallationSnapshot($this->rootPath, $this->workspace))->write($candidate);
        $this->selection = $candidate;
    }

    /** @param array<string, mixed> $candidate
     *  @return list<\Framework\Module\Module>
     */
    private function validate(array $candidate): array
    {
        InstallationSnapshot::validate($candidate);
        $enabled = $this->modules->enabled($candidate['enabled_modules']);
        $themes = clone $this->themes;
        $themes->select($candidate['active_theme'], $candidate['admin_theme']);
        $adminLayout = false;
        foreach ($themes->administrationLineage() as $theme) {
            if (is_file($theme->path . '/templates/layouts/base.html.twig')) {
                $adminLayout = true;
                break;
            }
        }
        if (!$adminLayout) {
            throw new \InvalidArgumentException('Administration theme has no base layout in its inheritance chain.');
        }
        $lineage = array_values(array_unique([...array_reverse($themes->activeLineage()), ...array_reverse($themes->administrationLineage())], SORT_REGULAR));
        $libraries = new LibraryRegistry($this->rootPath, $enabled, $lineage);
        (new LibraryResolver($libraries))->resolve(array_keys($libraries->all()));
        foreach ([...$themes->globalLibraries(), ...$themes->administrationLibraries()] as $id) {
            $libraries->get($id);
        }
        $this->candidateDatabases($enabled);
        return $enabled;
    }

    /** @param list<\Framework\Module\Module> $enabled */
    private function candidateDatabases(array $enabled): DatabaseRegistry
    {
        return new DatabaseRegistry($this->rootPath, $this->rootPath . '/config/database.yaml', $enabled, $this->configuration->file('database.yaml'), $this->workspace);
    }

    /** @return array<string, string> */
    private function rows(): array
    {
        $database = $this->database();
        if (!$database->connection()->createSchemaManager()->tablesExist(['config'])) {
            throw new \LogicException('Site configuration migration is pending; run database:migrate first.');
        }
        $rows = [];
        foreach ($database->query("SELECT setting_key, value FROM config WHERE workspace = :workspace AND setting_key IN ('installation.enabled_modules', 'installation.active_theme', 'installation.admin_theme')", ['workspace' => $this->workspace]) as $row) {
            $rows[$row['setting_key']] = $row['value'];
        }
        return $rows;
    }

    private function database(): DatabaseManager
    {
        $connection = $this->configuration->get('site.connection');
        if ($connection !== null && !is_string($connection)) {
            throw new \InvalidArgumentException('Invalid site configuration connection.');
        }
        return $this->databases->manager($connection);
    }

    private function requirePermission(string $permission): void
    {
        if ($this->security?->active()) {
            $this->security->requirePermission($permission);
        }
    }

    private function requireCli(): void
    {
        if ($this->security?->active()) {
            throw new \LogicException('Installation maintenance commands are CLI-only.');
        }
    }
}
