<?php

declare(strict_types=1);

namespace Framework\Bootstrap;

use Composer\Autoload\ClassLoader;
use Framework\Asset\AssetManager;
use Framework\Asset\LibraryRegistry;
use Framework\Asset\LibraryResolver;
use Framework\Config\ConfigManager;
use Framework\Config\WorkspaceResolver;
use Framework\Database\DatabaseRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Framework\Http\HttpKernel;
use Framework\Module\ModuleManager;
use Framework\Module\Module;
use Framework\Template\TemplateManager;
use Framework\Theme\ThemeManager;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;

final class Bootstrap
{
    private ?HttpKernel $kernel = null;
    private ?ModuleManager $modules = null;
    private ?ThemeManager $themes = null;
    private ?TemplateManager $templates = null;
    private ?LibraryRegistry $libraries = null;
    private ?AssetManager $assets = null;
    private ?DatabaseRegistry $databases = null;
    private ?ConfigManager $configuration = null;
    private ?string $workspace = null;
    private readonly WorkspaceResolver $workspaceResolver;
    /** @var list<Module> */
    private array $enabledModules = [];

    public function __construct(
        private readonly string $rootPath,
        private readonly ?string $configPath = null,
        private readonly ?string $selectedWorkspace = null,
    ) {
        $this->workspaceResolver = new WorkspaceResolver($rootPath . '/config');
    }

    public function boot(?Request $request = null): void
    {
        if ($this->kernel !== null && $request === null) {
            return;
        }
        $workspace = $this->workspaceResolver->resolve($request, $this->selectedWorkspace);
        if ($this->kernel !== null) {
            if ($workspace !== $this->workspace) {
                throw new \InvalidArgumentException('A booted Core cannot serve another workspace.');
            }
            return;
        }

        $configuration = new ConfigManager($this->rootPath . '/config', $workspace, $this->configPath);
        $config = $configuration->load();
        $loader = require $this->rootPath . '/vendor/autoload.php';

        if (!$loader instanceof ClassLoader) {
            throw new \RuntimeException('Composer autoload.php must return a ClassLoader.');
        }

        $modules = new ModuleManager($this->rootPath, $loader, $config['module_discovery_cache']);
        $modules->discover();
        $modules->registerNamespaces();
        $enabled = $modules->enabled($config['enabled_modules']);
        $databases = new DatabaseRegistry($this->rootPath, $this->rootPath . '/config/database.yaml', $enabled, $configuration->file('database.yaml'), $workspace);

        $themes = new ThemeManager($this->rootPath, $loader, $config['theme_discovery_cache']);
        $themes->discover();
        $themes->registerNamespaces();
        $themes->select($config['active_theme'], $config['admin_theme']);
        foreach ($modules->discover() as $module) {
            if (isset($themes->discover()[$module->id])) {
                throw new \InvalidArgumentException("Extension ID shared by module and theme: {$module->id}");
            }
        }
        $lineage = array_reverse($themes->activeLineage());
        $libraries = new LibraryRegistry($this->rootPath, $enabled, $lineage);
        $resolver = new LibraryResolver($libraries);
        $resolver->resolve(array_keys($libraries->all()));
        $assets = new AssetManager($resolver, $themes->globalLibraries());
        $assets->prepare();
        $assets->reset();
        $templates = new TemplateManager($this->rootPath, $themes, $enabled, $config['twig_cache'], $assets, $workspace);

        $container = new ContainerBuilder();
        $container->set(TemplateManager::class, $templates);
        $container->set(AssetManager::class, $assets);
        $container->set(DatabaseRegistry::class, $databases);
        foreach ($databases->all() as $name => $manager) {
            $managerId = 'database.' . $name;
            $container->set($managerId, $manager);
            $connection = new Definition(Connection::class);
            $connection->setFactory([new Reference($managerId), 'connection']);
            $connection->setPublic(true);
            $container->setDefinition($managerId . '.connection', $connection);
            if ($manager->hasOrm()) {
                $entityManager = new Definition(EntityManagerInterface::class);
                $entityManager->setFactory([new Reference($managerId), 'entityManager']);
                $entityManager->setPublic(true);
                $container->setDefinition($managerId . '.entity_manager', $entityManager);
            }
        }
        if (($defaultDatabase = $databases->defaultName()) !== null) {
            $container->setAlias(Connection::class, 'database.' . $defaultDatabase . '.connection')->setPublic(true);
            if ($databases->manager()->hasOrm()) {
                $container->setAlias(EntityManagerInterface::class, 'database.' . $defaultDatabase . '.entity_manager')->setPublic(true);
            }
        }
        $routes = new RouteCollection();

        foreach ($enabled as $module) {
            $servicesPath = $module->path . '/config/services.yaml';
            if (is_file($servicesPath)) {
                (new YamlFileLoader($container, new FileLocator(dirname($servicesPath))))->load(basename($servicesPath));
            }

            $routesPath = $module->path . '/config/routes.yaml';
            if (is_file($routesPath)) {
                $this->loadRoutes($routes, $routesPath);
            }
        }

        $container->compile();
        foreach ($routes as $route) {
            if (!$container->has($route->getDefault('_controller'))) {
                throw new \InvalidArgumentException('Route controller must be a public service: ' . $route->getDefault('_controller'));
            }
        }
        $this->enabledModules = $enabled;
        $this->modules = $modules;
        $this->themes = $themes;
        $this->templates = $templates;
        $this->libraries = $libraries;
        $this->assets = $assets;
        $this->databases = $databases;
        $this->configuration = $configuration;
        $this->workspace = $workspace;
        $this->kernel = new HttpKernel($routes, $container, $assets, $databases);
    }

    public function kernel(?Request $request = null): HttpKernel
    {
        $this->boot($request);

        return $this->kernel;
    }

    public function modules(): ModuleManager
    {
        $this->boot();

        return $this->modules;
    }

    /** @return list<Module> */
    public function enabledModules(): array
    {
        $this->boot();

        return $this->enabledModules;
    }

    public function themes(): ThemeManager
    {
        $this->boot();

        return $this->themes;
    }

    public function templates(): TemplateManager
    {
        $this->boot();

        return $this->templates;
    }

    public function libraries(): LibraryRegistry
    {
        $this->boot();

        return $this->libraries;
    }

    public function assets(): AssetManager
    {
        $this->boot();

        return $this->assets;
    }

    public function databases(): DatabaseRegistry
    {
        $this->boot();

        return $this->databases;
    }

    public function config(): ConfigManager
    {
        $this->boot();

        return $this->configuration;
    }

    public function workspace(): string
    {
        $this->boot();

        return $this->workspace;
    }

    private function loadRoutes(RouteCollection $routes, string $path): void
    {
        $definitions = Yaml::parseFile($path);
        if (!is_array($definitions)) {
            throw new \InvalidArgumentException("Routes file must be a mapping: {$path}");
        }

        foreach ($definitions as $name => $definition) {
            if (!is_string($name) || $name === '' || !is_array($definition)) {
                throw new \InvalidArgumentException("Invalid route definition in {$path}");
            }
            if ($routes->get($name) !== null) {
                throw new \InvalidArgumentException("Duplicate route name: {$name}");
            }
            $routePath = $definition['path'] ?? null;
            $controller = $definition['controller'] ?? null;
            $methods = $definition['methods'] ?? null;
            $requirements = $definition['requirements'] ?? [];
            if (!is_string($routePath) || !str_starts_with($routePath, '/') || !is_string($controller) || $controller === '' || !is_array($methods) || !array_is_list($methods) || $methods === [] || !is_array($requirements) || array_is_list($requirements) && $requirements !== [] || array_diff(array_keys($definition), ['path', 'controller', 'methods', 'requirements']) !== []) {
                throw new \InvalidArgumentException("Route {$name} needs a path, controller service and methods.");
            }
            foreach ($methods as $method) {
                if (!is_string($method) || !preg_match('/^[A-Z]+$/', $method)) {
                    throw new \InvalidArgumentException("Invalid HTTP method in route {$name}.");
                }
            }
            foreach ($requirements as $parameter => $pattern) {
                if (!is_string($parameter) || !is_string($pattern)) {
                    throw new \InvalidArgumentException("Invalid requirement in route {$name}.");
                }
            }
            $routes->add($name, new Route($routePath, ['_controller' => $controller], requirements: $requirements, methods: $methods));
        }
    }
}
