<?php

declare(strict_types=1);

use Framework\Asset\AssetManager;
use Framework\Asset\LibraryRegistry;
use Framework\Asset\LibraryResolver;
use Framework\Http\HttpKernel;
use Framework\Module\Module;
use Framework\Theme\Theme;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class LibraryTest extends TestCase
{
    private string $root;
    private string $modulePath;
    private string $themePath;
    private Module $module;
    private Theme $theme;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/framework-libraries-' . bin2hex(random_bytes(8));
        $this->modulePath = $this->root . '/modules/custom/example';
        $this->themePath = $this->root . '/themes/core/default';
        foreach ([$this->modulePath, $this->themePath] as $path) {
            mkdir($path . '/config', 0775, true);
            mkdir($path . '/assets/css', 0775, true);
            mkdir($path . '/assets/js', 0775, true);
        }
        foreach (['base', 'editor', 'shared'] as $name) {
            file_put_contents($this->modulePath . '/assets/js/' . $name . '.js', 'void 0;');
        }
        file_put_contents($this->modulePath . '/assets/css/base.css', 'body {}');
        file_put_contents($this->themePath . '/assets/css/site.css', 'body {}');
        $this->module = new Module('example', 'Example', $this->modulePath, []);
        $this->theme = new Theme('default', 'Default', $this->themePath, null, ['default/global']);
        $this->moduleLibraries(<<<'YAML'
base:
  version: 1.0.0
  css:
    css/base.css: {}
  js:
    js/base.js: {}
editor:
  dependencies: [example/base]
  js:
    js/editor.js: {}
bridge:
  dependencies: [example/editor]
duplicate:
  js:
    js/base.js: {}
YAML);
        file_put_contents($this->themePath . '/config/libraries.yaml', "global:\n  css: css/site.css\n");
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testDiscoversModuleAndThemeLibrariesAndResolvesNestedDependencies(): void
    {
        $registry = $this->registry();
        self::assertSame(['example/base', 'example/editor', 'example/bridge', 'example/duplicate', 'default/global'], array_keys($registry->all()));
        $ordered = (new LibraryResolver($registry))->resolve(['default/global', 'example/bridge', 'example/base']);
        self::assertSame(['default/global', 'example/base', 'example/editor', 'example/bridge'], array_map(static fn ($library): string => $library->id, $ordered));
    }

    public function testAttachedLibrariesAndFilesAreDeduplicated(): void
    {
        $assets = new AssetManager(new LibraryResolver($this->registry()), ['default/global']);
        $assets->attachLibrary('example/bridge');
        $assets->attachLibrary('example/bridge');
        $assets->attachLibrary('example/duplicate');
        $assets->prepare();
        self::assertSame(['/assets/modules/example/js/base.js', '/assets/modules/example/js/editor.js'], array_map(static fn ($asset): string => $asset->url, $assets->assets('js')));
        self::assertSame(['/assets/themes/default/css/site.css', '/assets/modules/example/css/base.css'], array_map(static fn ($asset): string => $asset->url, $assets->assets('css')));
    }

    public function testConflictingDuplicateFileFails(): void
    {
        $this->moduleLibraries("first:\n  js: js/base.js\nsecond:\n  js:\n    js/base.js:\n      async: true\n");
        $assets = new AssetManager(new LibraryResolver($this->registry()), []);
        $this->expectExceptionMessage('Conflicting asset configuration');
        $assets->prepare(['example/first', 'example/second']);
    }

    public function testMissingDependencyFailsRegistration(): void
    {
        $this->moduleLibraries("editor:\n  dependencies: [missing/base]\n");
        $this->expectExceptionMessage('requires unavailable library missing/base');
        $this->registry();
    }

    public function testCircularDependencyFailsResolution(): void
    {
        $this->moduleLibraries("first:\n  dependencies: [example/second]\nsecond:\n  dependencies: [example/first]\n");
        $this->expectExceptionMessage('Circular library dependency');
        (new LibraryResolver($this->registry()))->resolve(['example/first']);
    }

    public function testDependentScriptsRejectUnsafeExecutionOrder(): void
    {
        $this->moduleLibraries("base:\n  js:\n    js/base.js:\n      async: true\neditor:\n  dependencies: [example/base]\n  js: js/editor.js\n");
        $this->expectExceptionMessage('cannot guarantee script order');
        (new LibraryResolver($this->registry()))->resolve(['example/editor']);
    }

    public function testHeadScriptCannotDependOnBodyScript(): void
    {
        $this->moduleLibraries("base:\n  js: js/base.js\neditor:\n  dependencies: [example/base]\n  js:\n    js/editor.js:\n      position: head\n");
        $this->expectExceptionMessage('cannot guarantee script order');
        (new LibraryResolver($this->registry()))->resolve(['example/editor']);
    }

    public function testDisabledModuleLibraryIsUnavailable(): void
    {
        $registry = new LibraryRegistry($this->root, [], [$this->theme]);
        $this->expectExceptionMessage('Library not found or unavailable: example/base');
        $registry->get('example/base');
    }

    public function testThemeGlobalsUseParentThenChildAndRespectDependencies(): void
    {
        $childPath = $this->root . '/themes/custom/child';
        mkdir($childPath . '/config', 0775, true);
        file_put_contents($childPath . '/config/libraries.yaml', "global:\n  dependencies: [default/global]\n");
        $child = new Theme('child', 'Child', $childPath, 'default', ['child/global']);
        $registry = new LibraryRegistry($this->root, [$this->module], [$this->theme, $child]);
        $assets = new AssetManager(new LibraryResolver($registry), ['default/global', 'child/global']);
        $assets->prepare();
        self::assertSame(['/assets/themes/default/css/site.css'], array_map(static fn ($asset): string => $asset->url, $assets->assets('css')));
        self::assertSame(['default/global', 'child/global'], array_map(static fn ($library): string => $library->id, (new LibraryResolver($registry))->resolve(['default/global', 'child/global'])));
    }

    public function testThemeDependencyOnDisabledModuleFails(): void
    {
        file_put_contents($this->themePath . '/config/libraries.yaml', "global:\n  dependencies: [example/base]\n");
        $this->expectExceptionMessage('requires unavailable library example/base');
        new LibraryRegistry($this->root, [], [$this->theme]);
    }

    public function testMissingGlobalThemeLibraryFailsResolution(): void
    {
        $assets = new AssetManager(new LibraryResolver($this->registry()), ['default/absent']);
        $this->expectExceptionMessage('Library not found or unavailable: default/absent');
        $assets->prepare();
    }

    public function testMissingAndUnsafeLocalFilesFail(): void
    {
        $this->moduleLibraries("bad:\n  css: css/missing.css\n");
        $this->expectExceptionMessage('Library asset missing or unsafe');
        $this->registry();
    }

    public function testUnsafePathFails(): void
    {
        $this->moduleLibraries("bad:\n  css: css/../secret.css\n");
        $this->expectExceptionMessage('Invalid css asset path');
        $this->registry();
    }

    public function testRejectsPathPropertyInLibraryFile(): void
    {
        $this->moduleLibraries("old:\n  css:\n    - path: css/base.css\n");
        $this->expectExceptionMessage('path keys or a single path scalar');
        $this->registry();
    }

    public function testRejectsNullAssetGroup(): void
    {
        $this->moduleLibraries("bad:\n  css: null\n");
        $this->expectExceptionMessage('path keys or a single path scalar');
        $this->registry();
    }

    public function testPublishingOnlyCopiesDeclaredFiles(): void
    {
        mkdir($this->root . '/public');
        file_put_contents($this->modulePath . '/assets/js/private.js', 'private');
        $registry = $this->registry();
        $registry->publish('module');
        $registry->publish('theme');
        self::assertFileExists($this->root . '/public/assets/modules/example/js/base.js');
        self::assertFileExists($this->root . '/public/assets/themes/default/css/site.css');
        self::assertFileDoesNotExist($this->root . '/public/assets/modules/example/js/private.js');
    }

    public function testPublishingRejectsLinkedPublicAssetDirectory(): void
    {
        mkdir($this->root . '/public');
        mkdir($this->root . '/outside');
        symlink($this->root . '/outside', $this->root . '/public/assets');
        $registry = $this->registry();
        try {
            $registry->publish('module');
            self::fail('Expected linked public asset directory to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Linked public asset directory', $exception->getMessage());
            self::assertFileDoesNotExist($this->root . '/outside/modules/example/js/base.js');
        }
    }

    public function testLibraryDefinitionsRefreshOnNewRegistry(): void
    {
        self::assertArrayHasKey('example/editor', $this->registry()->all());
        $this->moduleLibraries("revised:\n  dependencies: []\n");
        self::assertSame(['example/revised', 'default/global'], array_keys($this->registry()->all()));
    }

    public function testModuleAndThemeCannotShareExtensionId(): void
    {
        $theme = new Theme('example', 'Other', $this->themePath, null, []);
        $this->expectExceptionMessage('Extension ID shared');
        new LibraryRegistry($this->root, [$this->module], [$theme]);
    }

    public function testHttpKernelClearsServiceAttachmentsAfterDirectResponse(): void
    {
        $assets = new AssetManager(new LibraryResolver($this->registry()), []);
        $controller = new class ($assets) {
            public function __construct(private readonly AssetManager $assets)
            {
            }

            public function __invoke(Request $request): Response
            {
                $this->assets->attachLibrary('example/base');
                return new Response('ok');
            }
        };
        $container = new ContainerBuilder();
        $container->set('test.controller', $controller);
        $routes = new RouteCollection();
        $routes->add('test', new Route('/test', ['_controller' => 'test.controller'], methods: ['GET']));
        $kernel = new HttpKernel($routes, $container, $assets);

        self::assertSame(200, $kernel->handle(Request::create('/test'))->getStatusCode());
        $assets->prepare();
        self::assertSame([], $assets->assets('css'));
        self::assertSame([], $assets->assets('js'));
    }

    private function registry(): LibraryRegistry
    {
        return new LibraryRegistry($this->root, [$this->module], [$this->theme]);
    }

    private function moduleLibraries(string $yaml): void
    {
        file_put_contents($this->modulePath . '/config/libraries.yaml', $yaml);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
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
