<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/core', __DIR__ . '/modules/core/content/src', __DIR__ . '/modules/core/content/migrations', __DIR__ . '/modules/core/user/src', __DIR__ . '/modules/core/user/migrations', __DIR__ . '/modules/core/admin/src', __DIR__ . '/modules/custom/example/src', __DIR__ . '/public', __DIR__ . '/tests'])
    ->append([__DIR__ . '/bin/console']);

return (new PhpCsFixer\Config())
    ->setRules(['@PSR12' => true, 'declare_strict_types' => true])
    ->setRiskyAllowed(true)
    ->setFinder($finder);
