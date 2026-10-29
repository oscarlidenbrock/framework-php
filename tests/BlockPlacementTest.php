<?php

declare(strict_types=1);

use Framework\Block\BlockInterface;
use Framework\Block\BlockRegistry;
use Framework\Database\DatabaseRegistry;
use Framework\Theme\ThemeManager;
use Module\Block\PlacementManager;
use PHPUnit\Framework\TestCase;

final class BlockPlacementTest extends TestCase
{
    private string $root;

    protected function tearDown(): void
    {
        if (!isset($this->root)) {
            return;
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testWorkspaceAndThemeIsolationAndInvalidRegion(): void
    {
        $root = $this->root = sys_get_temp_dir() . '/framework-placement-' . bin2hex(random_bytes(8));
        mkdir($root . '/themes/core/public', 0775, true);
        mkdir($root . '/themes/core/admin', 0775, true);
        file_put_contents($root . '/themes/core/public/theme.yaml', "id: public\nname: Public\nnamespace: PublicPlacement\nversion: 0.1.0\nregions:\n  page: {}\n");
        file_put_contents($root . '/themes/core/admin/theme.yaml', "id: admin\nname: Admin\nnamespace: AdminPlacement\nversion: 0.1.0\nregions:\n  sidebar: {}\n");
        $loader = require dirname(__DIR__) . '/vendor/autoload.php';
        $loader->addPsr4('Module\\Block\\', dirname(__DIR__) . '/modules/core/block/src');
        $themes = new ThemeManager($root, $loader);
        $themes->discover();
        $databases = new DatabaseRegistry($root, $root . '/config/database.yaml', [], ['database' => ['default' => 'main', 'connections' => ['main' => ['params' => ['driver' => 'pdo_sqlite', 'path' => $root . '/test.sqlite']]]]]);
        $databases->manager()->execute('CREATE TABLE config (workspace VARCHAR(100) NOT NULL, setting_key VARCHAR(200) NOT NULL, value TEXT NOT NULL, updated_at VARCHAR(32) NOT NULL, PRIMARY KEY (workspace, setting_key))');
        $blocks = new BlockRegistry();
        $blocks->register(new class () implements BlockInterface {
            public function id(): string
            {
                return 'external.demo';
            }
            public function label(): string
            {
                return 'Demo';
            }
            public function template(): string
            {
                return '@external/block.html.twig';
            }
            public function variables(): array
            {
                return [];
            }
            public function libraries(): array
            {
                return [];
            }
        });
        $first = new PlacementManager($databases, $themes, $blocks, 'first');
        $second = new PlacementManager($databases, $themes, $blocks, 'second');
        $placement = ['block' => 'external.demo', 'region' => 'page', 'weight' => 3, 'enabled' => true];
        $first->save('public', [$placement]);
        self::assertSame([$placement], $first->placements('public'));
        self::assertSame([], $first->placements('admin'));
        self::assertSame([], $second->placements('public'));
        $this->expectException(InvalidArgumentException::class);
        $first->save('admin', [$placement]);
    }
}
