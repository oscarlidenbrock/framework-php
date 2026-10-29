<?php

declare(strict_types=1);

namespace Framework\Config;

interface SiteSettingsInterface
{
    public function get(string $key): string;
}
