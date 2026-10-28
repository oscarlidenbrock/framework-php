<?php

declare(strict_types=1);

use Framework\Core;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CoreTest extends TestCase
{
    private string $configPath;

    protected function tearDown(): void
    {
        if (isset($this->configPath)) {
            unlink($this->configPath);
        }
    }

    public function testBootAndExampleRoute(): void
    {
        $core = new Core(dirname(__DIR__), workspace: 'development');
        $core->boot();
        $core->boot();

        self::assertSame(['example'], array_map(static fn ($module): string => $module->id, $core->enabledModules()));
        self::assertArrayHasKey('example', $core->modules()->discover());
        $response = $core->handle(Request::create('/example', 'GET'));
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
        $core = new Core(dirname(__DIR__), workspace: 'development');

        self::assertSame(404, $core->handle(Request::create('/missing'))->getStatusCode());
        $response = $core->handle(Request::create('/example', 'POST'));
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->headers->get('Allow'));
    }

    public function testActiveThemeOverridesModuleTemplateAndInheritsLayout(): void
    {
        $core = new Core(dirname(__DIR__), $this->config("enabled_modules: [example]\nactive_theme: contrast\n"), 'development');

        $response = $core->handle(Request::create('/example'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<main class="contrast-example">', $response->getContent());
        self::assertStringContainsString('<title>Example</title>', $response->getContent());
        self::assertSame('contrast', $core->themes()->active()->id);
        self::assertSame('default', $core->themes()->administration()->id);
    }

    public function testMissingActiveThemeFailsBoot(): void
    {
        $core = new Core(dirname(__DIR__), $this->config("enabled_modules: [example]\nactive_theme: missing\n"), 'development');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Active theme not found: missing');
        $core->boot();
    }

    public function testDisabledModuleHasNoRouteButRemainsDiscovered(): void
    {
        $core = new Core(dirname(__DIR__), $this->config("enabled_modules: []\n"), 'development');

        self::assertSame([], $core->enabledModules());
        self::assertArrayHasKey('example', $core->modules()->discover());
        self::assertSame(404, $core->handle(Request::create('/example'))->getStatusCode());
    }

    public function testUnknownEnabledModuleFailsDuringBoot(): void
    {
        $core = new Core(dirname(__DIR__), $this->config("enabled_modules: [missing]\n"), 'development');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Enabled module not found: missing');
        $core->boot();
    }

    public function testInvalidConfigFailsDuringBoot(): void
    {
        $core = new Core(dirname(__DIR__), $this->config("enabled_modules: example\n"), 'development');

        $this->expectException(InvalidArgumentException::class);
        $core->boot();
    }

    private function config(string $contents): string
    {
        $this->configPath = tempnam(sys_get_temp_dir(), 'framework-config-');
        file_put_contents($this->configPath, $contents);

        return $this->configPath;
    }
}
