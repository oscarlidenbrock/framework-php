<?php

declare(strict_types=1);

namespace Framework;

use Framework\Bootstrap\Bootstrap;
use Framework\Module\ModuleManager;
use Framework\Module\Module;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class Core
{
    private readonly Bootstrap $bootstrap;

    public function __construct(string $rootPath, ?string $configPath = null)
    {
        $this->bootstrap = new Bootstrap($rootPath, $configPath);
    }

    public function boot(): void
    {
        $this->bootstrap->boot();
    }

    public function handle(Request $request): Response
    {
        return $this->bootstrap->kernel()->handle($request);
    }

    public function modules(): ModuleManager
    {
        $this->boot();

        return $this->bootstrap->modules();
    }

    /** @return list<Module> */
    public function enabledModules(): array
    {
        return $this->bootstrap->enabledModules();
    }
}
