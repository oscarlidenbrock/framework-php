<?php

declare(strict_types=1);

namespace Framework\Config;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class WorkspaceResolver
{
    /** @var array<string, string>|null */
    private ?array $hosts = null;

    public function __construct(private readonly string $configDirectory)
    {
    }

    public function resolve(?Request $request, ?string $selected = null): string
    {
        $hosts = $this->hosts();
        $environment = getenv('APP_WORKSPACE');
        $fixed = $selected ?? ($environment === false || $environment === '' ? null : $environment);
        if ($fixed !== null) {
            $this->validateName($fixed);
        }
        if ($request === null) {
            if ($fixed === null) {
                throw new \InvalidArgumentException('CLI requires APP_WORKSPACE or --workspace.');
            }
            $workspace = $fixed;
        } else {
            try {
                $host = strtolower($request->getHost());
            } catch (\Throwable $exception) {
                throw new \InvalidArgumentException('Unconfigured or invalid hostname.', previous: $exception);
            }
            $workspace = $hosts[$host] ?? throw new \InvalidArgumentException('Unconfigured hostname.');
            if ($fixed !== null && $fixed !== $workspace) {
                throw new \InvalidArgumentException('Hostname does not match the selected workspace.');
            }
        }
        $root = realpath($this->configDirectory . '/workspaces');
        $path = realpath($this->configDirectory . '/workspaces/' . $workspace);
        if ($root === false || $path === false || !is_dir($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException("Workspace directory does not exist: {$workspace}");
        }

        return $workspace;
    }

    /** @return array<string, string> */
    private function hosts(): array
    {
        if ($this->hosts !== null) {
            return $this->hosts;
        }
        $path = $this->configDirectory . '/workspaces.yaml';
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('Missing or unreadable workspaces.yaml.');
        }
        try {
            $document = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw new \InvalidArgumentException('Invalid YAML in workspaces.yaml.', previous: $exception);
        }
        if (!is_array($document) || $document === [] || array_is_list($document)) {
            throw new \InvalidArgumentException('workspaces.yaml must map hostnames to workspaces.');
        }
        $hosts = [];
        foreach ($document as $host => $workspace) {
            if (!is_string($host) || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i', $host) || !is_string($workspace)) {
                throw new \InvalidArgumentException('Invalid hostname or workspace in workspaces.yaml.');
            }
            $this->validateName($workspace);
            $normalized = strtolower($host);
            if (isset($hosts[$normalized])) {
                throw new \InvalidArgumentException("Duplicate hostname in workspaces.yaml: {$normalized}");
            }
            $hosts[$normalized] = $workspace;
        }

        return $this->hosts = $hosts;
    }

    private function validateName(string $name): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException('Invalid workspace name.');
        }
    }
}
