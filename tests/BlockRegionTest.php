<?php

declare(strict_types=1);

use Framework\Asset\AssetManager;
use Framework\Asset\LibraryRegistry;
use Framework\Asset\LibraryResolver;
use Framework\Block\BlockInterface;
use Framework\Block\BlockRegistry;
use Framework\Block\RegionRenderer;
use Framework\Module\Module;
use Framework\Template\TemplateManager;
use Framework\Theme\ThemeManager;
use PHPUnit\Framework\TestCase;

final class BlockRegionTest extends TestCase
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

    public function testExternalBlockOrderEscapingSubregionsAndLegacyContent(): void
    {
        $root = $this->root = sys_get_temp_dir() . '/framework-block-' . bin2hex(random_bytes(8));
        mkdir($root . '/themes/core/base/templates/regions', 0775, true);
        mkdir($root . '/themes/core/base/templates/layouts', 0775, true);
        mkdir($root . '/modules/custom/external/templates', 0775, true);
        file_put_contents($root . '/themes/core/base/theme.yaml', "id: base\nname: Base\nnamespace: BlockBase\nversion: 0.1.0\nregions:\n  page: {}\n  nested: {parent: page}\n");
        file_put_contents($root . '/themes/core/base/templates/layouts/base.html.twig', "{% block content %}{% endblock %}{{ page }}");
        file_put_contents($root . '/themes/core/base/templates/regions/page.html.twig', '<section>{{ content }}{{ nested }}</section>');
        file_put_contents($root . '/modules/custom/external/templates/block.html.twig', '<b>{{ message }}</b>');
        file_put_contents($root . '/modules/custom/external/templates/page.html.twig', "{% extends '@theme/layouts/base.html.twig' %}{% block content %}Legacy{% endblock %}");
        $loader = require dirname(__DIR__) . '/vendor/autoload.php';
        $themes = new ThemeManager($root, $loader);
        $themes->select('base', 'base');
        $module = new Module('external', 'External', $root . '/modules/custom/external', []);
        $assets = new AssetManager(new LibraryResolver(new LibraryRegistry($root, [$module], $themes->activeLineage())), []);
        $templates = new TemplateManager($root, $themes, [$module], false, $assets);
        $blocks = new BlockRegistry();
        foreach (['first' => '<first>', 'second' => '<second>'] as $id => $message) {
            $blocks->register(new class ($id, $message) implements BlockInterface {
                public function __construct(private string $name, private string $message)
                {
                }
                public function id(): string
                {
                    return 'external.' . $this->name;
                }
                public function label(): string
                {
                    return $this->name;
                }
                public function template(): string
                {
                    return '@external/block.html.twig';
                }
                public function variables(): array
                {
                    return ['message' => $this->message];
                }
                public function libraries(): array
                {
                    return [];
                }
            });
        }
        $placements = static fn (string $theme): array => [
            ['block' => 'external.second', 'region' => 'nested', 'weight' => 0, 'enabled' => true],
            ['block' => 'external.first', 'region' => 'page', 'weight' => -1, 'enabled' => true],
        ];
        $templates->setRegionRenderer(new RegionRenderer($themes, $blocks, $assets, $templates, $placements(...)));
        $html = $templates->render('@external/page.html.twig');
        self::assertSame('Legacy<section><b>&lt;first&gt;</b><b>&lt;second&gt;</b></section>', $html);
    }
}
