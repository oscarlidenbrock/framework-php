<?php

declare(strict_types=1);

use Framework\Asset\AssetManager;
use Framework\Asset\LibraryRegistry;
use Framework\Asset\LibraryResolver;
use Framework\Module\Module;
use Framework\Template\TemplateManager;
use Framework\Theme\ThemeManager;
use PHPUnit\Framework\TestCase;
use Twig\Error\LoaderError;

final class TemplateManagerTest extends TestCase
{
    private string $root;
    private string $modulePath;
    private string $overridePath;
    private ThemeManager $themes;
    private Module $module;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/framework-templates-' . bin2hex(random_bytes(8));
        $base = $this->root . '/themes/core/base';
        $child = $this->root . '/themes/custom/child';
        $this->modulePath = $this->root . '/modules/custom/example';
        $this->overridePath = $child . '/templates/modules/example/index.html.twig';
        mkdir($base . '/templates/layouts', 0775, true);
        mkdir(dirname($this->overridePath), 0775, true);
        mkdir($this->modulePath . '/templates', 0775, true);
        file_put_contents($base . '/theme.yaml', "id: base\nname: Base\nnamespace: TemplateBaseTheme\nversion: 0.1.0\nparent: null\nlibraries: [base/global]\n");
        file_put_contents($child . '/theme.yaml', "id: child\nname: Child\nnamespace: TemplateChildTheme\nversion: 0.1.0\nparent: base\nlibraries: []\n");
        mkdir($base . '/config');
        mkdir($base . '/assets/css', 0775, true);
        file_put_contents($base . '/assets/css/site.css', 'body {}');
        file_put_contents($base . '/config/libraries.yaml', "global:\n  css: css/site.css\n");
        mkdir($this->modulePath . '/config');
        mkdir($this->modulePath . '/assets/js', 0775, true);
        file_put_contents($this->modulePath . '/assets/js/example.js', 'void 0;');
        file_put_contents($this->modulePath . '/config/libraries.yaml', "page:\n  js:\n    js/example.js:\n      async: true\n      position: head\n");
        file_put_contents($base . '/templates/layouts/base.html.twig', file_get_contents(dirname(__DIR__) . '/themes/core/default/templates/layouts/base.html.twig'));
        file_put_contents($this->modulePath . '/templates/index.html.twig', "{% extends '@theme/layouts/base.html.twig' %}{% block content %}Module: {{ message }}{% endblock %}");
        file_put_contents($this->overridePath, "{% extends '@theme/layouts/base.html.twig' %}{% block content %}Override: {{ message }}{% endblock %}");

        $this->module = new Module('example', 'Example', $this->modulePath, []);
        $loader = require dirname(__DIR__) . '/vendor/autoload.php';
        $this->themes = new ThemeManager($this->root, $loader);
        $this->themes->select('child', 'base');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testThemeOverrideAndParentLayoutTakePrecedence(): void
    {
        self::assertStringContainsString('Override: &lt;script&gt;', $this->templates()->render('@example/index.html.twig', ['message' => '<script>']));
    }

    public function testModuleFallbackAfterOverrideRemoval(): void
    {
        unlink($this->overridePath);
        self::assertStringContainsString('Module: text', $this->templates()->render('@example/index.html.twig', ['message' => 'text']));
    }

    public function testParentOverrideBeforeModuleFallback(): void
    {
        unlink($this->overridePath);
        $path = $this->root . '/themes/core/base/templates/modules/example/index.html.twig';
        mkdir(dirname($path), 0775, true);
        file_put_contents($path, "{% extends '@theme/layouts/base.html.twig' %}{% block content %}Parent: {{ message }}{% endblock %}");
        self::assertStringContainsString('Parent: text', $this->templates()->render('@example/index.html.twig', ['message' => 'text']));
    }

    public function testMissingTemplateRaisesLoaderError(): void
    {
        $this->expectException(LoaderError::class);
        $this->templates()->render('@example/missing.html.twig');
    }

    public function testDisabledModuleHasNoTemplateNamespace(): void
    {
        $this->expectException(LoaderError::class);
        $this->templates(false, false)->render('@example/index.html.twig');
    }

    public function testProductionTwigCacheOutsidePublic(): void
    {
        $this->templates(true)->render('@example/index.html.twig', ['message' => 'cached']);
        self::assertDirectoryExists($this->root . '/var/cache/default/twig');
    }

    public function testRenderResponseAttachesLibrariesAndPreservesVariables(): void
    {
        $response = $this->templates()->renderResponse('@example/index.html.twig', ['libraries' => ['example/page']], ['message' => 'hello'], 201);
        $html = $response->getContent();
        self::assertSame(201, $response->getStatusCode());
        self::assertStringContainsString('href="/assets/themes/base/css/site.css"', $html);
        self::assertStringContainsString('src="/assets/modules/example/js/example.js" async', $html);
        self::assertLessThan(strpos($html, '</head>'), strpos($html, '/assets/modules/example/js/example.js'));
        self::assertStringContainsString('Override: hello', $html);
    }

    public function testAttachedServiceLibraryDoesNotLeakBetweenResponses(): void
    {
        [$templates, $assets] = $this->services();
        $assets->attachLibrary('example/page');
        $first = $templates->renderResponse('@example/index.html.twig', [], ['message' => 'first'])->getContent();
        $second = $templates->renderResponse('@example/index.html.twig', [], ['message' => 'second'])->getContent();
        self::assertStringContainsString('/assets/modules/example/js/example.js', $first);
        self::assertStringNotContainsString('/assets/modules/example/js/example.js', $second);
    }

    public function testRejectsLegacyResponseAssets(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('direct css and js assets are forbidden');
        $this->templates()->renderResponse('@example/index.html.twig', ['css' => ['css/site.css']], ['message' => 'x']);
    }

    public function testRejectsMissingLibrary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Library not found or unavailable');
        $this->templates()->renderResponse('@example/index.html.twig', ['libraries' => ['example/missing']], ['message' => 'x']);
    }

    private function templates(bool $cache = false, bool $enabled = true): TemplateManager
    {
        return $this->services($cache, $enabled)[0];
    }

    /** @return array{TemplateManager, AssetManager} */
    private function services(bool $cache = false, bool $enabled = true): array
    {
        $modules = $enabled ? [$this->module] : [];
        $registry = new LibraryRegistry($this->root, $modules, array_reverse($this->themes->activeLineage()));
        $assets = new AssetManager(new LibraryResolver($registry), ['base/global']);
        return [new TemplateManager($this->root, $this->themes, $modules, $cache, $assets), $assets];
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
