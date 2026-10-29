<?php

declare(strict_types=1);

namespace Module\Site;

use Framework\Config\ConfigManager;
use Framework\Config\SiteSettingsInterface;
use Framework\Database\DatabaseManager;
use Framework\Database\DatabaseRegistry;
use Framework\Security\RequestSecurityInterface;

final class SiteSettings implements SiteSettingsInterface
{
    /** @var array{title: string, email: string} */
    private readonly array $defaults;
    private readonly ?string $connectionName;

    public function __construct(
        ConfigManager $config,
        private readonly DatabaseRegistry $databases,
        private readonly string $workspace,
        private readonly ?RequestSecurityInterface $security = null,
    ) {
        $settings = $config->get('site');
        if (!is_array($settings) || array_diff(array_keys($settings), ['title', 'email', 'connection']) !== [] || !is_string($settings['title'] ?? null) || !is_string($settings['email'] ?? null)) {
            throw new \InvalidArgumentException('Invalid site settings defaults.');
        }
        $connection = $settings['connection'] ?? null;
        if ($connection !== null && (!is_string($connection) || !preg_match('/^[a-z][a-z0-9_]*$/', $connection))) {
            throw new \InvalidArgumentException('Invalid site settings connection.');
        }
        $this->defaults = [
            'title' => $this->validate('title', $settings['title']),
            'email' => $this->validate('email', $settings['email']),
        ];
        $this->connectionName = $connection;
    }

    public function get(string $key): string
    {
        $this->requireKey($key);
        $overrides = $this->overrides();
        return array_key_exists($key, $overrides) ? $overrides[$key] : $this->defaults[$key];
    }

    public function default(string $key): string
    {
        $this->requireKey($key);
        return $this->defaults[$key];
    }

    /** @return array<string, array{default: string, effective: string, overridden: bool}> */
    public function details(): array
    {
        if ($this->security?->active()) {
            $this->security->requirePermission('site.settings.view');
        }
        $overrides = $this->overrides();
        $details = [];
        foreach ($this->defaults as $key => $default) {
            $overridden = array_key_exists($key, $overrides);
            $details[$key] = ['default' => $default, 'effective' => $overridden ? $overrides[$key] : $default, 'overridden' => $overridden];
        }
        return $details;
    }

    /** @param array<string, string|null> $updates */
    public function save(array $updates): void
    {
        if ($this->security?->active()) {
            $this->security->requirePermission('site.settings.edit');
        }
        foreach ($updates as $key => $value) {
            $this->requireKey($key);
            if ($value !== null) {
                $updates[$key] = $this->validate($key, $value);
            }
        }
        $database = $this->database() ?? throw new \LogicException('Site settings require a configured database connection.');
        if (!$database->connection()->createSchemaManager()->tablesExist(['config'])) {
            throw new \LogicException('Site settings migration is pending; run database:migrate first.');
        }
        $database->transaction(function (DatabaseManager $database) use ($updates): void {
            foreach ($updates as $key => $value) {
                if ($value === null) {
                    $database->execute('DELETE FROM config WHERE workspace = :workspace AND setting_key = :key', ['workspace' => $this->workspace, 'key' => $key]);
                    continue;
                }
                $parameters = ['workspace' => $this->workspace, 'key' => $key, 'value' => $value, 'updated' => gmdate('Y-m-d H:i:s.u')];
                $existing = $database->queryOne('SELECT 1 FROM config WHERE workspace = :workspace AND setting_key = :key', ['workspace' => $this->workspace, 'key' => $key]);
                if ($existing === null) {
                    $database->execute('INSERT INTO config (workspace, setting_key, value, updated_at) VALUES (:workspace, :key, :value, :updated)', $parameters);
                } else {
                    $database->execute('UPDATE config SET value = :value, updated_at = :updated WHERE workspace = :workspace AND setting_key = :key', $parameters);
                }
            }
        });
    }

    /** @return array<string, string> */
    private function overrides(): array
    {
        $database = $this->database();
        if ($database === null || !$database->connection()->createSchemaManager()->tablesExist(['config'])) {
            return [];
        }
        $overrides = [];
        foreach ($database->query('SELECT setting_key, value FROM config WHERE workspace = :workspace', ['workspace' => $this->workspace]) as $row) {
            if (array_key_exists($row['setting_key'], $this->defaults)) {
                $overrides[$row['setting_key']] = $row['value'];
            }
        }
        return $overrides;
    }

    private function database(): ?DatabaseManager
    {
        if ($this->connectionName === null && $this->databases->defaultName() === null) {
            return null;
        }
        return $this->databases->manager($this->connectionName);
    }

    private function requireKey(string $key): void
    {
        if (!array_key_exists($key, $this->defaults)) {
            throw new \InvalidArgumentException('Unknown site setting.');
        }
    }

    private function validate(string $key, string $value): string
    {
        $value = trim($value);
        if ($key === 'title') {
            if ($value === '' || mb_strlen($value) > 120 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new \InvalidArgumentException('Site title must contain 1 to 120 visible characters.');
            }
        } elseif ($key === 'email' && (strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false)) {
            throw new \InvalidArgumentException('Site email must be a valid email address.');
        }
        return $value;
    }
}
