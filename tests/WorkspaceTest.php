<?php

declare(strict_types=1);

use Framework\Config\ConfigManager;
use Framework\Config\WorkspaceResolver;
use Framework\Core;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class WorkspaceTest extends TestCase
{
    private string $root;
    private string|false $originalEnvironment;

    protected function setUp(): void
    {
        $this->originalEnvironment = getenv('APP_WORKSPACE');
        putenv('APP_WORKSPACE');
        $this->root = sys_get_temp_dir() . '/framework-workspace-' . bin2hex(random_bytes(8));
        foreach (['vendor', 'config/workspaces/development', 'config/workspaces/production', 'modules/custom/fixture', 'themes/core/default', 'themes/custom/contrast'] as $path) {
            mkdir($this->root . '/' . $path, 0775, true);
        }
        file_put_contents($this->root . '/vendor/autoload.php', '<?php return require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';');
        file_put_contents($this->root . '/config/workspaces.yaml', "LOCALHOST: development\nwww.example.com: production\n");
        file_put_contents($this->root . '/config/app.yaml', "enabled_modules: []\nactive_theme: default\ndebug: false\n");
        file_put_contents($this->root . '/config/modules.yaml', "enabled_modules: []\n");
        file_put_contents($this->root . '/config/database.yaml', "database:\n  default: primary\n  connections:\n    primary:\n      driver: pdo_sqlite\n      memory: true\n      orm: true\n    analytics:\n      driver: pdo_sqlite\n      memory: true\n      orm: false\n");
        file_put_contents($this->root . '/config/workspaces/production/app.yaml', "active_theme: contrast\ndebug: true\n");
        file_put_contents($this->root . '/config/workspaces/production/modules.yaml', "enabled_modules: [fixture]\n");
        file_put_contents($this->root . '/config/workspaces/production/database.yaml', "database:\n  default: analytics\n  connections:\n    primary:\n      memory: false\n      path: /tmp/workspace-production.sqlite\n");
        file_put_contents($this->root . '/modules/custom/fixture/module.yaml', "id: fixture\nname: Fixture\nnamespace: FixtureWorkspace\nversion: 0.1.0\nrequires: []\n");
        file_put_contents($this->root . '/themes/core/default/theme.yaml', "id: default\nname: Default\nnamespace: DefaultWorkspace\nversion: 0.1.0\nparent: null\n");
        file_put_contents($this->root . '/themes/custom/contrast/theme.yaml', "id: contrast\nname: Contrast\nnamespace: ContrastWorkspace\nversion: 0.1.0\nparent: default\n");
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        if ($this->originalEnvironment === false) {
            putenv('APP_WORKSPACE');
        } else {
            putenv('APP_WORKSPACE=' . $this->originalEnvironment);
        }
    }

    public function testRecursiveMergeReplacesListsAndExplicitEmptyValues(): void
    {
        $base = ['map' => ['keep' => 1, 'change' => 2], 'list' => [1, 2], 'nullable' => 'value', 'flag' => true, 'zero' => 9, 'empty' => 'text', 'clear' => ['nested' => 1]];
        $override = ['map' => ['change' => 3, 'new' => 4], 'list' => [7], 'nullable' => null, 'flag' => false, 'zero' => 0, 'empty' => '', 'clear' => []];
        $merged = ConfigManager::merge($base, $override);
        self::assertSame(['keep' => 1, 'change' => 3, 'new' => 4], $merged['map']);
        self::assertSame([7], $merged['list']);
        self::assertNull($merged['nullable']);
        self::assertFalse($merged['flag']);
        self::assertSame(0, $merged['zero']);
        self::assertSame('', $merged['empty']);
        self::assertSame([], $merged['clear']);
        self::assertSame(2, $base['map']['change']);
    }

    public function testFilesMergeAndWorkspaceOnlyFile(): void
    {
        file_put_contents($this->root . '/config/cache.yaml', "cache:\n  enabled: true\n  tags: [global]\n  options: {ttl: 60, stable: true}\n");
        file_put_contents($this->root . '/config/workspaces/production/cache.yaml', "cache:\n  enabled: false\n  tags: []\n  options: {ttl: 0}\n");
        file_put_contents($this->root . '/config/workspaces/production/only.yaml', "only:\n  value: workspace\n");
        $config = new ConfigManager($this->root . '/config', 'production');
        self::assertSame(['enabled' => false, 'tags' => [], 'options' => ['ttl' => 0, 'stable' => true]], $config->get('cache'));
        self::assertSame('workspace', $config->get('only.value'));
        self::assertSame('pdo_sqlite', $config->get('database.connections.analytics.driver'));
        self::assertSame('/tmp/workspace-production.sqlite', $config->get('database.connections.primary.path'));
        self::assertTrue($config->get('app.debug'));
        self::assertNull($config->get('missing.key'));
        self::assertSame('default', (new ConfigManager($this->root . '/config', 'development'))->get('app.active_theme'));
    }

    public function testHttpHostSelectionAndFixedWorkspaceMismatch(): void
    {
        $resolver = new WorkspaceResolver($this->root . '/config');
        self::assertSame('development', $resolver->resolve(Request::create('http://LOCALHOST:8080/example')));
        self::assertSame('production', $resolver->resolve(Request::create('http://www.example.com/')));
        self::assertSame('development', $resolver->resolve(Request::create('http://localhost/', server: ['HTTP_X_FORWARDED_HOST' => 'www.example.com'])));
        $this->expectExceptionMessage('Hostname does not match');
        $resolver->resolve(Request::create('http://www.example.com/'), 'development');
    }

    public function testHttpEnvironmentLocksWorkspace(): void
    {
        putenv('APP_WORKSPACE=development');
        $resolver = new WorkspaceResolver($this->root . '/config');
        self::assertSame('development', $resolver->resolve(Request::create('http://localhost/')));
        $this->expectExceptionMessage('Hostname does not match');
        $resolver->resolve(Request::create('http://www.example.com/'));
    }

    public function testUnknownHostAndInvalidWorkspaceAreRejected(): void
    {
        $resolver = new WorkspaceResolver($this->root . '/config');
        try {
            $resolver->resolve(Request::create('http://unknown.example/'));
            self::fail('Expected an unknown host error.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('hostname', strtolower($exception->getMessage()));
        }
        $this->expectExceptionMessage('Invalid workspace name');
        $resolver->resolve(null, '../secrets');
    }

    public function testCliRequiresSelectionAndExplicitOptionOverridesEnvironment(): void
    {
        $resolver = new WorkspaceResolver($this->root . '/config');
        try {
            $resolver->resolve(null);
            self::fail('Expected a missing workspace error.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('APP_WORKSPACE', $exception->getMessage());
        }
        putenv('APP_WORKSPACE=development');
        self::assertSame('development', $resolver->resolve(null));
        self::assertSame('production', $resolver->resolve(null, 'production'));
    }

    public function testConsoleSelectsWorkspaceBeforeModuleRegistration(): void
    {
        mkdir($this->root . '/bin');
        copy(dirname(__DIR__) . '/bin/console', $this->root . '/bin/console');
        [$code, $output] = $this->console(['module:list']);
        self::assertSame(1, $code);
        self::assertStringContainsString('APP_WORKSPACE', $output);

        putenv('APP_WORKSPACE=development');
        [$code, $output] = $this->console(['module:list']);
        self::assertSame(0, $code);
        self::assertStringContainsString("fixture\tdisabled", $output);

        [$code, $output] = $this->console(['--workspace=production', 'module:list']);
        self::assertSame(0, $code);
        self::assertStringContainsString("fixture\tenabled", $output);
    }

    public function testBootstrapUsesOneWorkspaceForModulesThemesAndDoctrine(): void
    {
        $development = new Core($this->root, workspace: 'development');
        $development->boot();
        self::assertSame('development', $development->workspace());
        self::assertSame([], $development->enabledModules());
        self::assertSame('default', $development->themes()->active()->id);
        self::assertSame('primary', $development->database()->name);
        self::assertTrue($development->database('primary')->connection()->getParams()['memory']);

        $production = new Core($this->root, workspace: 'production');
        $production->boot();
        self::assertSame('production', $production->workspace());
        self::assertSame(['fixture'], array_map(static fn ($module): string => $module->id, $production->enabledModules()));
        self::assertSame('contrast', $production->themes()->active()->id);
        self::assertSame('analytics', $production->database()->name);
        self::assertFalse($production->database('primary')->connection()->getParams()['memory']);
        self::assertNotSame($development->database('primary'), $production->database('primary'));
        self::assertNotSame($development->config(), $production->config());
    }

    public function testOneCoreRejectsAnotherWorkspaceRequest(): void
    {
        $core = new Core($this->root);
        self::assertSame(404, $core->handle(Request::create('http://localhost/unknown'))->getStatusCode());
        self::assertSame('development', $core->workspace());
        $this->expectExceptionMessage('A booted Core cannot serve another workspace');
        $core->handle(Request::create('http://www.example.com/unknown'));
    }

    public function testTwigCachesAreSeparatedByWorkspace(): void
    {
        mkdir($this->root . '/themes/core/default/templates/layouts', 0775, true);
        mkdir($this->root . '/themes/custom/contrast/templates/layouts', 0775, true);
        file_put_contents($this->root . '/themes/core/default/templates/layouts/base.html.twig', 'development layout');
        file_put_contents($this->root . '/themes/custom/contrast/templates/layouts/base.html.twig', 'production layout');
        file_put_contents($this->root . '/config/app.yaml', "enabled_modules: []\nactive_theme: default\ntwig_cache: true\n");
        $development = new Core($this->root, workspace: 'development');
        $production = new Core($this->root, workspace: 'production');

        self::assertSame('development layout', $development->templates()->render('@theme/layouts/base.html.twig'));
        self::assertSame('production layout', $production->templates()->render('@theme/layouts/base.html.twig'));
        self::assertDirectoryExists($this->root . '/storage/cache/twig/development');
        self::assertDirectoryExists($this->root . '/storage/cache/twig/production');
    }

    public function testMalformedYamlAndMissingWorkspaceFailClearly(): void
    {
        file_put_contents($this->root . '/config/workspaces/production/cache.yaml', "cache: [unterminated\n");
        $this->expectExceptionMessage('Invalid YAML in cache.yaml');
        (new ConfigManager($this->root . '/config', 'production'))->get('cache');
    }

    public function testWorkspaceDirectoryCannotEscapeConfig(): void
    {
        $this->expectExceptionMessage('Invalid workspace name');
        new ConfigManager($this->root . '/config', '../development');
    }

    public function testMissingWorkspaceDirectoryFails(): void
    {
        file_put_contents($this->root . '/config/workspaces.yaml', "missing.example: missing\n");
        $this->expectExceptionMessage('Workspace directory does not exist');
        (new WorkspaceResolver($this->root . '/config'))->resolve(Request::create('http://missing.example/'));
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

    /** @param list<string> $arguments
     *  @return array{int, string}
     */
    private function console(array $arguments): array
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, $this->root . '/bin/console', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start CLI fixture.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }
}
