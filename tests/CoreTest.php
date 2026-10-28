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
        $core = new Core(dirname(__DIR__));
        $core->boot();
        $core->boot();

        self::assertSame(['example'], array_map(static fn ($module): string => $module->id, $core->enabledModules()));
        self::assertArrayHasKey('example', $core->modules()->discover());
        $response = $core->handle(Request::create('/example', 'GET'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Example module is running', $response->getContent());
        self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
    }

    public function testNotFoundAndMethodNotAllowed(): void
    {
        $core = new Core(dirname(__DIR__));

        self::assertSame(404, $core->handle(Request::create('/missing'))->getStatusCode());
        $response = $core->handle(Request::create('/example', 'POST'));
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->headers->get('Allow'));
    }

    public function testDisabledModuleHasNoRouteButRemainsDiscovered(): void
    {
        $core = new Core(dirname(__DIR__), $this->config("enabled_modules: []\n"));

        self::assertSame([], $core->enabledModules());
        self::assertArrayHasKey('example', $core->modules()->discover());
        self::assertSame(404, $core->handle(Request::create('/example'))->getStatusCode());
    }

    public function testUnknownEnabledModuleFailsDuringBoot(): void
    {
        $core = new Core(dirname(__DIR__), $this->config("enabled_modules: [missing]\n"));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Enabled module not found: missing');
        $core->boot();
    }

    public function testInvalidConfigFailsDuringBoot(): void
    {
        $core = new Core(dirname(__DIR__), $this->config("enabled_modules: example\n"));

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
