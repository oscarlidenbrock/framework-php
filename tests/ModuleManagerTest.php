<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Framework\Module\ModuleManager;
use PHPUnit\Framework\TestCase;

final class ModuleManagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/framework-modules-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/modules/custom', 0775, true);
        mkdir($this->root . '/modules/contrib', 0775, true);
        mkdir($this->root . '/modules/core', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testDiscoversAllCategoriesAndRegistersNamespaces(): void
    {
        $this->module('core', 'first', 'FixtureFirst');
        $this->module('contrib', 'second', 'FixtureSecond');
        $path = $this->module('custom', 'third', 'FixtureThird');
        mkdir($path . '/src');
        file_put_contents($path . '/src/Probe.php', "<?php namespace Module\\FixtureThird; final class Probe {}\n");

        $manager = new ModuleManager($this->root, $this->loader());
        self::assertSame(['first', 'second', 'third'], array_keys($manager->discover()));
        $manager->registerNamespaces();
        self::assertTrue(class_exists('Module\\FixtureThird\\Probe'));
        self::assertSame([], $manager->enabled([]));
    }

    public function testOrdersDependenciesAndRejectsDisabledDependency(): void
    {
        $this->module('core', 'base', 'FixtureBase');
        $this->module('custom', 'child', 'FixtureChild', ['base']);
        $manager = new ModuleManager($this->root, $this->loader());

        self::assertSame(['base', 'child'], array_map(static fn ($module): string => $module->id, $manager->enabled(['child', 'base'])));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires enabled module base');
        $manager->enabled(['child']);
    }

    public function testRejectsCircularDependencies(): void
    {
        $this->module('core', 'first', 'CycleFirst', ['second']);
        $this->module('custom', 'second', 'CycleSecond', ['first']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Circular module dependency');
        (new ModuleManager($this->root, $this->loader()))->enabled(['first', 'second']);
    }

    public function testRejectsDuplicateNamespaceAcrossCategories(): void
    {
        $this->module('core', 'first', 'SameName');
        $this->module('custom', 'second', 'Samename');

        $this->expectException(InvalidArgumentException::class);
        (new ModuleManager($this->root, $this->loader()))->discover();
    }

    public function testRejectsMissingManifest(): void
    {
        mkdir($this->root . '/modules/custom/broken');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing module manifest');
        (new ModuleManager($this->root, $this->loader()))->discover();
    }

    public function testReservesThemeIdForTwigNamespace(): void
    {
        $this->module('custom', 'theme', 'ConflictingModule');

        $this->expectException(InvalidArgumentException::class);
        (new ModuleManager($this->root, $this->loader()))->discover();
    }

    public function testCacheIsRebuiltWhenManifestChanges(): void
    {
        $path = $this->module('custom', 'first', 'CachedFirst');
        $manager = new ModuleManager($this->root, $this->loader(), true);
        self::assertCount(1, $manager->discover());
        self::assertFileExists($this->root . '/storage/cache/modules.json');

        file_put_contents($path . '/module.yaml', "id: first\nname: first\nnamespace: CachedFirst\nversion: 0.1.0\nrequires: [missing]\n");
        $fresh = new ModuleManager($this->root, $this->loader(), true);
        self::assertSame(['missing'], $fresh->discover()['first']->requires);
    }

    public function testRejectsLegacyAssetsInManifest(): void
    {
        $this->module('custom', 'asset', 'LegacyAssetModule', [], "assets:\n  css: [css/site.css]\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Direct module assets are forbidden');
        (new ModuleManager($this->root, $this->loader()))->discover();
    }

    public function testModuleDatabaseAssignmentSurvivesDiscoveryCache(): void
    {
        $this->module('custom', 'record', 'RecordModule', [], "database:\n  entity_manager: legacy\n  mappings:\n    analytics: [src/AnalyticsEntity]\n");
        $loader = $this->loader();
        $first = (new ModuleManager($this->root, $loader, true))->discover()['record'];
        $cached = (new ModuleManager($this->root, $loader, true))->discover()['record'];
        self::assertSame('legacy', $first->database['entity_manager']);
        self::assertSame($first->database, $cached->database);
    }

    /** @param list<string> $requires */
    private function module(string $category, string $id, string $namespace, array $requires = [], string $extra = ''): string
    {
        $path = $this->root . "/modules/{$category}/{$id}";
        mkdir($path);
        $dependencies = $requires === [] ? '[]' : '[' . implode(', ', $requires) . ']';
        file_put_contents($path . '/module.yaml', "id: {$id}\nname: {$id}\nnamespace: {$namespace}\nversion: 0.1.0\nrequires: {$dependencies}\n{$extra}");

        return $path;
    }

    private function loader(): ClassLoader
    {
        return require dirname(__DIR__) . '/vendor/autoload.php';
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
