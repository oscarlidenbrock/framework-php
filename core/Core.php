<?php

declare(strict_types=1);

namespace Framework;

use Framework\Bootstrap\Bootstrap;
use Framework\Config\ConfigManager;
use Framework\Asset\AssetManager;
use Framework\Asset\LibraryRegistry;
use Framework\Database\DatabaseManager;
use Framework\Database\DatabaseRegistry;
use Framework\Module\ModuleManager;
use Framework\Module\Module;
use Framework\Template\TemplateManager;
use Framework\Theme\ThemeManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;

class Core
{
    private readonly Bootstrap $bootstrap;

    public function __construct(string $rootPath, ?string $configPath = null, ?string $workspace = null)
    {
        $this->bootstrap = new Bootstrap($rootPath, $configPath, $workspace);
    }

    public function boot(): void
    {
        $this->bootstrap->boot();
    }

    public function handle(Request $request): Response
    {
        return $this->bootstrap->kernel($request)->handle($request);
    }

    public function modules(): ModuleManager
    {
        $this->boot();

        return $this->bootstrap->modules();
    }

    /** @return list<Module> */
    public function enabledModules(): array
    {
        return $this->bootstrap->enabledModules();
    }

    public function themes(): ThemeManager
    {
        return $this->bootstrap->themes();
    }

    public function templates(): TemplateManager
    {
        return $this->bootstrap->templates();
    }

    public function libraries(): LibraryRegistry
    {
        return $this->bootstrap->libraries();
    }

    public function assets(): AssetManager
    {
        return $this->bootstrap->assets();
    }

    public function database(?string $name = null): DatabaseManager
    {
        return $this->bootstrap->databases()->manager($name);
    }

    public function databases(): DatabaseRegistry
    {
        return $this->bootstrap->databases();
    }

    public function config(): ConfigManager
    {
        return $this->bootstrap->config();
    }

    public function workspace(): string
    {
        return $this->bootstrap->workspace();
    }

    public function container(): ContainerInterface
    {
        return $this->bootstrap->container();
    }
}
