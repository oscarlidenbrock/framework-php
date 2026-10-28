<?php

declare(strict_types=1);

use Framework\Asset\AssetNormalizer;
use Framework\Asset\AssetRegistry;
use Framework\Asset\AssetRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssetTest extends TestCase
{
    public function testScalarPathUsesDefaultAttributes(): void
    {
        $assets = (new AssetNormalizer())->normalizeGroups(['css' => 'css/site.css', 'js' => 'js/site.js'], 'test');
        self::assertSame('css/site.css', $assets['css'][0]->path);
        self::assertSame('head', $assets['css'][0]->position);
        self::assertSame([], $assets['css'][0]->attributes);
        self::assertSame('body', $assets['js'][0]->position);
        self::assertSame(['async' => false, 'defer' => true], $assets['js'][0]->attributes);
    }

    public function testPathKeysPreserveOrderAndOptions(): void
    {
        $assets = (new AssetNormalizer())->normalizeGroups(['css' => [
            'css/first.css' => [],
            'css/print.css' => ['media' => 'print'],
        ]], 'test');
        self::assertSame(['css/first.css', 'css/print.css'], array_map(static fn ($asset): string => $asset->path, $assets['css']));
        self::assertSame('print', $assets['css'][1]->attributes['media']);
    }

    public function testStructuredAttributesAndEscapedHtml(): void
    {
        $normalizer = new AssetNormalizer();
        $integrity = 'sha256-' . base64_encode(str_repeat('a', 32));
        $css = $normalizer->normalize('css/print.css', [
            'media' => 'print',
            'type' => 'text/css',
            'integrity' => $integrity,
            'crossorigin' => 'anonymous',
            'referrerpolicy' => 'no-referrer',
            'fetchpriority' => 'high',
            'attributes' => ['id' => 'print"sheet', 'disabled' => true, 'data-hidden' => false],
        ], 'css', 'test')->withUrl('/assets/print.css?x=1&y="');
        $js = $normalizer->normalize('js/site.js', [
            'async' => true,
            'position' => 'head',
            'attributes' => ['data-note' => '<safe>'],
        ], 'js', 'test')->withUrl('/assets/site.js');
        $renderer = new AssetRenderer();

        $link = $renderer->stylesheets([$css]);
        $script = $renderer->scripts([$js], 'head');
        self::assertStringContainsString('href="/assets/print.css?x=1&amp;y=&quot;"', $link);
        self::assertStringContainsString('media="print"', $link);
        self::assertStringContainsString('integrity="' . $integrity . '"', $link);
        self::assertStringContainsString('id="print&quot;sheet"', $link);
        self::assertStringContainsString(' disabled', $link);
        self::assertStringNotContainsString('data-hidden', $link);
        self::assertStringContainsString(' async', $script);
        self::assertStringNotContainsString(' defer', $script);
        self::assertStringContainsString('data-note="&lt;safe&gt;"', $script);
        self::assertSame('', $renderer->scripts([$js], 'body'));
    }

    public function testRegistryDeduplicatesAndPreservesOrder(): void
    {
        $normalizer = new AssetNormalizer();
        $first = $normalizer->normalize('css/first.css', [], 'css', 'test')->withUrl('/first.css');
        $second = $normalizer->normalize('css/second.css', [], 'css', 'test')->withUrl('/second.css');
        $registry = new AssetRegistry();
        $registry->add($first);
        $registry->add($second);
        $registry->add($first);
        self::assertSame(['/first.css', '/second.css'], array_map(static fn ($asset): string => $asset->url, $registry->ofType('css')));
    }

    public function testRegistryRejectsConflictingConfiguration(): void
    {
        $normalizer = new AssetNormalizer();
        $registry = new AssetRegistry();
        $registry->add($normalizer->normalize('css/site.css', [], 'css', 'test')->withUrl('/site.css'));
        $this->expectException(InvalidArgumentException::class);
        $registry->add($normalizer->normalize('css/site.css', ['media' => 'print'], 'css', 'test')->withUrl('/site.css'));
    }

    public function testExplicitFalseScriptOptionOmitsDeferAttribute(): void
    {
        $asset = (new AssetNormalizer())->normalize('js/site.js', ['defer' => false], 'js', 'test')->withUrl('/site.js');
        self::assertStringNotContainsString(' defer', (new AssetRenderer())->scripts([$asset], 'body'));
    }

    public function testOldPathPropertyAndListsAreRejected(): void
    {
        $normalizer = new AssetNormalizer();
        foreach ([['css' => [['path' => 'css/site.css']]], ['css' => ['css/site.css']]] as $groups) {
            try {
                $normalizer->normalizeGroups($groups, 'test');
                self::fail('Expected old list format to fail.');
            } catch (InvalidArgumentException) {
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $normalizer->normalize('css/site.css', ['path' => 'css/other.css'], 'css', 'test');
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('invalidDefinitions')]
    public function testInvalidDefinitionsAreRejected(string $type, string $path, array $options): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AssetNormalizer())->normalize($path, $options, $type, 'test');
    }

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function invalidDefinitions(): iterable
    {
        yield 'event handler' => ['js', 'js/site.js', ['attributes' => ['onclick' => 'alert(1)']]];
        yield 'unsafe protocol in path' => ['js', 'javascript:alert(1)', []];
        yield 'unsafe protocol in custom attribute' => ['js', 'js/site.js', ['attributes' => ['data-url' => 'data:text/html,evil']]];
        yield 'override source attribute' => ['js', 'js/site.js', ['attributes' => ['src' => 'https://evil.test']]];
        yield 'inline style' => ['css', 'css/site.css', ['attributes' => ['style' => 'display:none']]];
        yield 'async and defer' => ['js', 'js/site.js', ['async' => true, 'defer' => true]];
        yield 'module with defer' => ['js', 'js/site.js', ['type' => 'module', 'defer' => true]];
        yield 'media on script' => ['js', 'js/site.js', ['media' => 'print']];
        yield 'defer on CSS' => ['css', 'css/site.css', ['defer' => true]];
        yield 'invalid position' => ['js', 'js/site.js', ['position' => 'footer']];
        yield 'invalid crossorigin' => ['css', 'css/site.css', ['crossorigin' => 'credentials']];
        yield 'invalid referrerpolicy' => ['css', 'css/site.css', ['referrerpolicy' => 'always']];
        yield 'invalid fetchpriority' => ['js', 'js/site.js', ['fetchpriority' => 'urgent']];
        yield 'invalid integrity' => ['js', 'js/site.js', ['integrity' => 'sha256-bad']];
        yield 'null option' => ['js', 'js/site.js', ['async' => null]];
        yield 'wrong boolean type' => ['js', 'js/site.js', ['async' => 'true']];
        yield 'parent traversal' => ['css', 'css/../secret.css', []];
    }
}
