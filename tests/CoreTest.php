<?php

declare(strict_types=1);

use Framework\Core;
use Framework\Config\InstallationSnapshot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/framework-core-' . bin2hex(random_bytes(8));
        foreach (['vendor', 'config/workspaces/development', 'modules/custom', 'themes/core', 'themes/custom'] as $path) {
            mkdir($this->root . '/' . $path, 0775, true);
        }
        file_put_contents($this->root . '/vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';');
        file_put_contents($this->root . '/config/workspaces.yaml', "framework.test: development\n");
        file_put_contents($this->root . '/config/app.yaml', "enabled_modules: [example]\nactive_theme: default\nadmin_theme: admin_theme\n");
        $this->copyDirectory(dirname(__DIR__) . '/modules/custom/example', $this->root . '/modules/custom/example');
        foreach (['default', 'admin'] as $name) {
            $this->copyDirectory(dirname(__DIR__) . '/themes/core/' . $name, $this->root . '/themes/core/' . $name);
        }
        $this->copyDirectory(dirname(__DIR__) . '/themes/custom/contrast', $this->root . '/themes/custom/contrast');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testBootAndExampleRoute(): void
    {
        $core = $this->core("enabled_modules: [example]\n");
        $core->boot();
        $core->boot();

        self::assertSame(['example'], array_map(static fn ($module): string => $module->id, $core->enabledModules()));
        self::assertArrayHasKey('example', $core->modules()->discover());
        $response = $core->handle(Request::create('http://framework.test/example', 'GET'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<title>Example</title>', $response->getContent());
        self::assertStringContainsString('<p>Example module is running</p>', $response->getContent());
        self::assertStringContainsString('href="/assets/modules/example/css/example.css"', $response->getContent());
        self::assertStringContainsString('media="screen"', $response->getContent());
        self::assertStringContainsString('src="/assets/modules/example/js/example.js"', $response->getContent());
        self::assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
    }

    public function testNotFoundAndMethodNotAllowed(): void
    {
        $core = $this->core("enabled_modules: [example]\n");

        self::assertSame(404, $core->handle(Request::create('http://framework.test/missing'))->getStatusCode());
        $response = $core->handle(Request::create('http://framework.test/example', 'POST'));
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->headers->get('Allow'));
    }

    public function testActiveThemeOverridesModuleTemplateAndInheritsLayout(): void
    {
        $core = $this->core("enabled_modules: [example]\nactive_theme: contrast\n");

        $response = $core->handle(Request::create('http://framework.test/example'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<main class="contrast-example">', $response->getContent());
        self::assertStringContainsString('<title>Example</title>', $response->getContent());
        self::assertSame('contrast', $core->themes()->active()->id);
        self::assertSame('admin_theme', $core->themes()->administration()->id);
    }

    public function testMissingActiveThemeFailsBoot(): void
    {
        $core = $this->core("enabled_modules: [example]\nactive_theme: missing\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Active theme not found: missing');
        $core->boot();
    }

    public function testDisabledModuleHasNoRouteButRemainsDiscovered(): void
    {
        $core = $this->core("enabled_modules: []\n");

        self::assertSame([], $core->enabledModules());
        self::assertArrayHasKey('example', $core->modules()->discover());
        self::assertSame(404, $core->handle(Request::create('http://framework.test/example'))->getStatusCode());
    }

    public function testUnknownEnabledModuleFailsDuringBoot(): void
    {
        $core = $this->core("enabled_modules: [missing]\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Enabled module not found: missing');
        $core->boot();
    }

    public function testInvalidConfigFailsDuringBoot(): void
    {
        $core = $this->core("enabled_modules: example\n");

        $this->expectException(InvalidArgumentException::class);
        $core->boot();
    }

    public function testTemporaryYamlOverridesWorkspaceSeed(): void
    {
        file_put_contents($this->root . '/config/workspaces/development/app.yaml', "enabled_modules: []\nactive_theme: contrast\n");
        $core = $this->core("enabled_modules: [example]\nactive_theme: default\n");

        self::assertSame(['example'], array_map(static fn ($module): string => $module->id, $core->enabledModules()));
        self::assertSame('default', $core->themes()->active()->id);
    }

    public function testPersistedSelectionOverridesTemporaryYamlAndWorkspaceSeed(): void
    {
        file_put_contents($this->root . '/config/workspaces/development/app.yaml', "enabled_modules: [example]\nactive_theme: contrast\n");
        file_put_contents($this->root . '/config/modules.yaml', "enabled_modules: [example]\n");
        (new InstallationSnapshot($this->root, 'development'))->write(['enabled_modules' => [], 'active_theme' => 'default', 'admin_theme' => 'admin_theme']);
        $core = $this->core("enabled_modules: [example]\nactive_theme: contrast\n");

        self::assertSame([], $core->enabledModules());
        self::assertSame('default', $core->themes()->active()->id);
        self::assertSame([], $core->config()->get('modules.enabled_modules'));
        self::assertSame([], $core->config()->get('app.enabled_modules'));
        self::assertSame('default', $core->config()->get('app.active_theme'));
    }

    public function testModulesFilePrecedesTemporaryAppYamlWithoutSnapshot(): void
    {
        file_put_contents($this->root . '/config/workspaces/development/modules.yaml', "enabled_modules: []\n");
        $core = $this->core("enabled_modules: [example]\n");

        self::assertSame([], $core->enabledModules());
        self::assertSame([], $core->config()->get('modules.enabled_modules'));
        self::assertSame(['example'], $core->config()->get('app.enabled_modules'));
    }

    private function core(string $contents): Core
    {
        $path = $this->root . '/app-override.yaml';
        file_put_contents($path, $contents);

        return new Core($this->root, $path, 'development');
    }

    private function copyDirectory(string $source, string $target): void
    {
        mkdir($target, 0775, true);
        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($source . '/' . $entry)) {
                $this->copyDirectory($source . '/' . $entry, $target . '/' . $entry);
            } else {
                copy($source . '/' . $entry, $target . '/' . $entry);
            }
        }
    }

    private function remove(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
