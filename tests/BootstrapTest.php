<?php

declare(strict_types=1);

use Framework\Core;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class BootstrapTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/framework-bootstrap-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/vendor', 0775, true);
        mkdir($this->root . '/config');
        mkdir($this->root . '/modules/custom/fixture/config', 0775, true);
        mkdir($this->root . '/modules/custom/fixture/src/Controller', 0775, true);
        file_put_contents($this->root . '/vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';');
        file_put_contents($this->root . '/config/app.yaml', "enabled_modules: [fixture]\n");
        file_put_contents($this->root . '/modules/custom/fixture/module.yaml', "id: fixture\nname: Fixture\nnamespace: FixtureGreeting\nversion: 0.1.0\nrequires: []\n");
        file_put_contents($this->root . '/modules/custom/fixture/config/services.yaml', "services:\n  fixture.controller:\n    class: Module\\FixtureGreeting\\Controller\\GreetingController\n    public: true\n");
        file_put_contents($this->root . '/modules/custom/fixture/config/routes.yaml', "fixture.index:\n  path: /fixture\n  methods: [GET]\n  controller: fixture.controller\n");
        file_put_contents($this->root . '/modules/custom/fixture/src/Controller/GreetingController.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Module\FixtureGreeting\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class GreetingController
{
    public function __invoke(Request $request): Response
    {
        return new Response('Fixture works');
    }
}
PHP);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testIndependentModuleProvidesRouteAndService(): void
    {
        $core = new Core($this->root);

        self::assertSame('Fixture works', $core->handle(Request::create('/fixture'))->getContent());
        self::assertSame(['fixture'], array_map(static fn ($module): string => $module->id, $core->enabledModules()));
    }

    public function testUnsupportedAccessRuleFailsBoot(): void
    {
        file_put_contents($this->root . '/modules/custom/fixture/config/routes.yaml', "fixture.index:\n  path: /fixture\n  methods: [GET]\n  controller: fixture.controller\n  access: admin\n");

        $this->expectException(InvalidArgumentException::class);
        (new Core($this->root))->boot();
    }

    public function testControllerMustBePublicService(): void
    {
        file_put_contents($this->root . '/modules/custom/fixture/config/services.yaml', "services:\n  fixture.controller:\n    class: Module\\FixtureGreeting\\Controller\\GreetingController\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Route controller must be a public service');
        (new Core($this->root))->boot();
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
