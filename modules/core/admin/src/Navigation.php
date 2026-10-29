<?php

declare(strict_types=1);

namespace Module\Admin;

use Framework\Security\AuthorizationInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Yaml\Yaml;

final class Navigation
{
    /** @param array<string, string> $modulePaths */
    public function __construct(
        private readonly array $modulePaths,
        private readonly UrlGeneratorInterface $urls,
        private readonly AuthorizationInterface $authorization,
    ) {
    }

    /** @return list<array{label: string, url: string}> */
    public function items(): array
    {
        $items = [];
        foreach ($this->modulePaths as $path) {
            $file = $path . '/config/admin_navigation.yaml';
            if (!is_file($file)) {
                continue;
            }
            $entries = Yaml::parseFile($file);
            if (!is_array($entries) || array_is_list($entries)) {
                throw new \InvalidArgumentException('Invalid administration navigation configuration.');
            }
            foreach ($entries as $entry) {
                if (!is_array($entry) || array_diff(array_keys($entry), ['label', 'route', 'permission']) !== [] || !is_string($entry['label'] ?? null) || !is_string($entry['route'] ?? null) || !is_string($entry['permission'] ?? null)) {
                    throw new \InvalidArgumentException('Invalid administration navigation entry.');
                }
                if ($this->authorization->can($entry['permission'])) {
                    $items[] = ['label' => $entry['label'], 'url' => $this->urls->generate($entry['route'])];
                }
            }
        }
        return $items;
    }
}
