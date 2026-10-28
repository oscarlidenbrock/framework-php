<?php

declare(strict_types=1);

namespace Framework\Bootstrap;

use Composer\Autoload\ClassLoader;
use Framework\Config\ConfigManager;
use Framework\Http\HttpKernel;
use Framework\Module\ModuleManager;
use Framework\Module\Module;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Yaml\Yaml;

final class Bootstrap
{
    private ?HttpKernel $kernel = null;
    private ?ModuleManager $modules = null;
    /** @var list<Module> */
    private array $enabledModules = [];

    public function __construct(
        private readonly string $rootPath,
        private readonly ?string $configPath = null,
    ) {
    }

    public function boot(): void
    {
        if ($this->kernel !== null) {
            return;
        }

        $config = (new ConfigManager($this->configPath ?? $this->rootPath . '/config/app.yaml'))->load();
        $loader = require $this->rootPath . '/vendor/autoload.php';

        if (!$loader instanceof ClassLoader) {
            throw new \RuntimeException('Composer autoload.php must return a ClassLoader.');
        }

        $modules = new ModuleManager($this->rootPath, $loader, $config['module_discovery_cache']);
        $modules->discover();
        $modules->registerNamespaces();
        $enabled = $modules->enabled($config['enabled_modules']);

        $container = new ContainerBuilder();
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
        $this->kernel = new HttpKernel($routes, $container);
    }

    public function kernel(): HttpKernel
    {
        $this->boot();

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
