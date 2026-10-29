<?php

declare(strict_types=1);

use Framework\Theme\ThemeManager;
use PHPUnit\Framework\TestCase;

final class ThemeManagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/framework-themes-' . bin2hex(random_bytes(8));
        foreach (['core', 'contrib', 'custom'] as $category) {
            mkdir($this->root . '/themes/' . $category, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testDiscoversInheritanceAndRegistersNamespace(): void
    {
        $this->theme('core', 'base', 'FixtureBaseTheme', null, "libraries: [base/global]\n");
        $this->theme('contrib', 'middle', 'FixtureMiddleTheme', 'base', "libraries: [middle/global]\n");
        $child = $this->theme('custom', 'child', 'FixtureChildTheme', 'middle');
        mkdir($child . '/src');
        file_put_contents($child . '/src/Probe.php', "<?php namespace Theme\\FixtureChildTheme; final class Probe {}\n");

        $manager = new ThemeManager($this->root, require dirname(__DIR__) . '/vendor/autoload.php', true);
        self::assertSame(['base', 'middle', 'child'], array_keys($manager->discover()));
        $manager->registerNamespaces();
        self::assertTrue(class_exists('Theme\\FixtureChildTheme\\Probe'));
        $manager->select('child', 'base');
        self::assertSame(['child', 'middle', 'base'], array_map(static fn ($theme): string => $theme->id, $manager->activeLineage()));
        self::assertSame(['base/global'], $manager->activeLineage()[2]->libraries);
        self::assertSame(['base/global', 'middle/global'], $manager->globalLibraries());
        self::assertSame(['base/global'], (new ThemeManager($this->root, require dirname(__DIR__) . '/vendor/autoload.php', true))->discover()['base']->libraries);
    }

    public function testRegionInheritanceAndSubregions(): void
    {
        $this->theme('core', 'base', 'RegionBaseTheme', null, "regions:
  page: {}
  sidebar: {parent: page}
");
        $this->theme('custom', 'child', 'RegionChildTheme', 'base', "regions:
  sidebar: {}
  footer: {parent: page}
");
        self::assertSame(['page' => null, 'sidebar' => null, 'footer' => 'page'], $this->manager()->regions('child'));
    }

    public function testCircularRegionNestingFailsDiscovery(): void
    {
        $this->theme('core', 'base', 'RegionCycleTheme', null, "regions:
  page: {parent: sidebar}
  sidebar: {parent: page}
");
        $this->expectExceptionMessage('Circular region nesting');
        $this->manager()->discover();
    }

    public function testMissingRegionParentFailsDiscovery(): void
    {
        $this->theme('core', 'base', 'RegionMissingTheme', null, "regions:
  sidebar: {parent: absent}
");
        $this->expectExceptionMessage('unknown parent');
        $this->manager()->discover();
    }

    public function testMissingParentFailsDiscovery(): void
    {
        $this->theme('custom', 'child', 'MissingParentTheme', 'absent');
        $this->expectExceptionMessage('Theme parent not found: absent');
        $this->manager()->discover();
    }

    public function testCircularInheritanceFailsDiscovery(): void
    {
        $this->theme('core', 'first', 'CircularFirstTheme', 'second');
        $this->theme('custom', 'second', 'CircularSecondTheme', 'first');
        $this->expectExceptionMessage('Circular theme inheritance');
        $this->manager()->discover();
    }

    public function testMissingAdministrationThemeFailsSelection(): void
    {
        $this->theme('core', 'base', 'AdministrationBaseTheme');
        $this->expectExceptionMessage('Administration theme not found: missing');
        $this->manager()->select('base', 'missing');
    }

    public function testDuplicateNamespaceFailsDiscovery(): void
    {
        $this->theme('core', 'first', 'DuplicateTheme');
        $this->theme('custom', 'second', 'Duplicatetheme');
        $this->expectExceptionMessage('Duplicate theme namespace');
        $this->manager()->discover();
    }

    public function testRejectsLegacyManifestAssets(): void
    {
        $this->theme('core', 'base', 'LegacyTheme', null, "assets:\n  css: [css/site.css]\n");
        $this->expectExceptionMessage('Direct theme assets are forbidden');
        $this->manager()->discover();
    }

    public function testCacheRefreshesWhenManifestChanges(): void
    {
        $path = $this->theme('core', 'base', 'CacheBaseTheme');
        self::assertCount(1, (new ThemeManager($this->root, require dirname(__DIR__) . '/vendor/autoload.php', true))->discover());
        file_put_contents($path . '/theme.yaml', "id: base\nname: Base changed\nnamespace: CacheBaseTheme\nversion: 0.2.0\nparent: absent\n");
        $this->expectExceptionMessage('Theme parent not found');
        (new ThemeManager($this->root, require dirname(__DIR__) . '/vendor/autoload.php', true))->discover();
    }

    private function theme(string $category, string $id, string $namespace, ?string $parent = null, string $extra = ''): string
    {
        $path = $this->root . "/themes/{$category}/{$id}";
        mkdir($path);
        $parentYaml = $parent ?? 'null';
        file_put_contents($path . '/theme.yaml', "id: {$id}\nname: {$id}\nnamespace: {$namespace}\nversion: 0.1.0\nparent: {$parentYaml}\n{$extra}");

        return $path;
    }

    private function manager(): ThemeManager
    {
        return new ThemeManager($this->root, require dirname(__DIR__) . '/vendor/autoload.php');
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
