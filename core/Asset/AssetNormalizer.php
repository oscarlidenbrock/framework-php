<?php

declare(strict_types=1);

namespace Framework\Asset;

final class AssetNormalizer
{
    /** @return array{css: list<AssetDefinition>, js: list<AssetDefinition>, fonts: list<AssetDefinition>} */
    public function normalizeGroups(mixed $groups, string $context): array
    {
        if (!is_array($groups) || array_diff(array_keys($groups), ['css', 'js', 'fonts']) !== []) {
            throw new \InvalidArgumentException("Invalid asset groups: {$context}");
        }

        return [
            'css' => $this->normalizeEntries(array_key_exists('css', $groups) ? $groups['css'] : [], 'css', $context),
            'js' => $this->normalizeEntries(array_key_exists('js', $groups) ? $groups['js'] : [], 'js', $context),
            'fonts' => $this->normalizeEntries(array_key_exists('fonts', $groups) ? $groups['fonts'] : [], 'fonts', $context),
        ];
    }

    /** @return list<AssetDefinition> */
    public function normalizeEntries(mixed $items, string $type, string $context): array
    {
        if (is_string($items)) {
            return [$this->normalize($items, [], $type, $context)];
        }
        if (!in_array($type, ['css', 'js', 'fonts'], true) || !is_array($items) || array_is_list($items) && $items !== []) {
            throw new \InvalidArgumentException("{$type} assets must use path keys or a single path scalar: {$context}");
        }
        $result = [];
        foreach ($items as $path => $options) {
            if (!is_string($path) || !is_array($options) || array_is_list($options) && $options !== []) {
                throw new \InvalidArgumentException("Invalid {$type} asset options: {$context}");
            }
            $result[] = $this->normalize($path, $options, $type, $context);
        }

        return $result;
    }

    /** @param array<string, mixed> $options */
    public function normalize(string $path, array $options, string $type, string $context): AssetDefinition
    {
        $external = $options['external'] ?? false;
        if (!is_bool($external) || $external && !in_array($type, ['css', 'js'], true)) {
            throw new \InvalidArgumentException("Invalid external asset declaration: {$context}");
        }
        if ($external) {
            $this->validateExternalUrl($path, $context);
            unset($options['external']);
        }
        $allowed = match ($type) {
            'css' => ['media', 'type', 'position', 'integrity', 'crossorigin', 'referrerpolicy', 'fetchpriority', 'attributes'],
            'js' => ['defer', 'async', 'type', 'position', 'integrity', 'crossorigin', 'referrerpolicy', 'fetchpriority', 'attributes'],
            'fonts' => ['position', 'type', 'crossorigin', 'fetchpriority', 'attributes'],
            default => [],
        };
        if (!in_array($type, ['css', 'js', 'fonts'], true) || array_diff(array_keys($options), $allowed) !== []) {
            throw new \InvalidArgumentException("Unsupported {$type} asset property: {$context}");
        }
        if (in_array(null, $options, true)) {
            throw new \InvalidArgumentException("Null asset property: {$context}");
        }
        $extension = $type === 'fonts' ? '(?:woff2?|ttf|otf)' : $type;
        if (!$external && (!preg_match('#^' . $type . '/[A-Za-z0-9_./-]+\.' . $extension . '$#', $path) || str_contains($path, '//') || in_array('.', explode('/', $path), true) || in_array('..', explode('/', $path), true))) {
            throw new \InvalidArgumentException("Invalid {$type} asset path: {$context}");
        }

        $position = $options['position'] ?? ($type === 'js' ? 'body' : 'head');
        if (!is_string($position) || !preg_match('/^[a-z][a-z0-9_]*$/', $position)) {
            throw new \InvalidArgumentException("Invalid asset position: {$context}");
        }
        $attributes = [];
        if (isset($options['media'])) {
            $attributes['media'] = $this->nonemptyText($options['media'], 'media', $context);
        }
        if (isset($options['type'])) {
            $allowedTypes = match ($type) {
                'css' => ['text/css'],
                'js' => ['module', 'text/javascript', 'application/javascript'],
                'fonts' => ['font/woff2', 'font/woff', 'font/ttf', 'font/otf'],
            };
            if (!is_string($options['type']) || !in_array($options['type'], $allowedTypes, true)) {
                throw new \InvalidArgumentException("Invalid asset type: {$context}");
            }
            $attributes['type'] = $options['type'];
        }
        foreach (['integrity', 'crossorigin', 'referrerpolicy', 'fetchpriority'] as $name) {
            if (!isset($options[$name])) {
                continue;
            }
            $text = $this->nonemptyText($options[$name], $name, $context);
            $allowedValues = match ($name) {
                'crossorigin' => ['anonymous', 'use-credentials'],
                'referrerpolicy' => ['no-referrer', 'no-referrer-when-downgrade', 'origin', 'origin-when-cross-origin', 'same-origin', 'strict-origin', 'strict-origin-when-cross-origin', 'unsafe-url'],
                'fetchpriority' => ['high', 'low', 'auto'],
                default => null,
            };
            if ($allowedValues !== null && !in_array($text, $allowedValues, true)) {
                throw new \InvalidArgumentException("Invalid {$name} value: {$context}");
            }
            if ($name === 'integrity') {
                foreach (preg_split('/\s+/', $text) as $hash) {
                    if (!preg_match('#^sha(256|384|512)-([A-Za-z0-9+/]+={0,2})$#', $hash, $match)) {
                        throw new \InvalidArgumentException("Invalid integrity value: {$context}");
                    }
                    $decoded = base64_decode($match[2], true);
                    $expectedLength = match ($match[1]) {
                        '256' => 32,
                        '384' => 48,
                        '512' => 64,
                    };
                    if ($decoded === false || strlen($decoded) !== $expectedLength) {
                        throw new \InvalidArgumentException("Invalid integrity value: {$context}");
                    }
                }
            }
            $attributes[$name] = $text;
        }
        if ($external && isset($options['integrity']) && !isset($options['crossorigin'])) {
            $attributes['crossorigin'] = 'anonymous';
        }
        if ($type === 'js') {
            $async = $options['async'] ?? false;
            $defer = $options['defer'] ?? (!$async && ($attributes['type'] ?? null) !== 'module');
            if (!is_bool($async) || !is_bool($defer) || $async && $defer || ($attributes['type'] ?? null) === 'module' && $defer) {
                throw new \InvalidArgumentException("Incompatible script attributes: {$context}");
            }
            $attributes['async'] = $async;
            $attributes['defer'] = $defer;
        }
        if ($type === 'fonts') {
            $format = pathinfo($path, PATHINFO_EXTENSION);
            if (isset($options['type']) && $options['type'] !== 'font/' . $format) {
                throw new \InvalidArgumentException("Font type does not match file extension: {$context}");
            }
            $attributes['type'] = $options['type'] ?? 'font/' . $format;
            $attributes['crossorigin'] = $options['crossorigin'] ?? 'anonymous';
        }
        $custom = $options['attributes'] ?? [];
        if (!is_array($custom) || array_is_list($custom) && $custom !== []) {
            throw new \InvalidArgumentException("Invalid custom asset attributes: {$context}");
        }
        foreach ($custom as $name => $attributeValue) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9-]*$/', $name) || preg_match('/^on/i', $name) || in_array($name, ['href', 'src', 'rel', 'as', 'style', 'media', 'defer', 'async', 'type', 'position', 'integrity', 'crossorigin', 'referrerpolicy', 'fetchpriority'], true) || !(is_string($attributeValue) || is_int($attributeValue) || is_bool($attributeValue))) {
                throw new \InvalidArgumentException("Unsafe custom asset attribute: {$context}");
            }
            if (is_string($attributeValue) && preg_match('/^\s*([a-z][a-z0-9+.-]*):/i', $attributeValue, $match) && !in_array(strtolower($match[1]), ['http', 'https'], true)) {
                throw new \InvalidArgumentException("Unsafe custom asset protocol: {$context}");
            }
            $attributes[$name] = $attributeValue;
        }
        ksort($attributes);

        return new AssetDefinition($type, $path, $position, $attributes, $external ? $path : null);
    }

    private function validateExternalUrl(string $url, string $context): void
    {
        $parts = parse_url($url);
        if ($parts === false || !is_string($parts['scheme'] ?? null) || strtolower($parts['scheme']) !== 'https' || !is_string($parts['host'] ?? null) || $parts['host'] === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7F\\\\]/', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException("Invalid external HTTPS asset URL: {$context}");
        }
    }

    private function nonemptyText(mixed $value, string $name, string $context): string
    {
        if (!is_string($value) || trim($value) === '' || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException("Invalid {$name} value: {$context}");
        }

        return $value;
    }
}
