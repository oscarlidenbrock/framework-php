<?php

declare(strict_types=1);

namespace Framework\Template;

use Framework\Asset\AssetExtension;
use Framework\Asset\AssetManager;
use Framework\Module\Module;
use Framework\Theme\ThemeManager;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class TemplateManager
{
    private readonly Environment $twig;

    /** @param list<Module> $enabledModules */
    public function __construct(
        string $rootPath,
        ThemeManager $themes,
        array $enabledModules,
        bool $cache,
        private readonly AssetManager $assets,
    ) {
        $loader = new FilesystemLoader();
        foreach ($themes->activeLineage() as $theme) {
            if (is_dir($theme->path . '/templates')) {
                $loader->addPath($theme->path . '/templates', 'theme');
            }
        }
        foreach ($enabledModules as $module) {
            foreach ($themes->activeLineage() as $theme) {
                $override = $theme->path . '/templates/modules/' . $module->id;
                if (is_dir($override)) {
                    $loader->addPath($override, $module->id);
                }
            }
            if (is_dir($module->path . '/templates')) {
                $loader->addPath($module->path . '/templates', $module->id);
            }
        }

        $this->twig = new Environment($loader, [
            'cache' => $cache ? $rootPath . '/storage/cache/twig' : false,
            'auto_reload' => !$cache,
            'autoescape' => 'html',
            'strict_variables' => true,
        ]);
        $this->twig->addExtension(new AssetExtension($assets));
    }

    /** @param array<string, mixed> $context */
    public function render(string $template, array $context = []): string
    {
        try {
            $this->assets->prepare();
            return $this->twig->render($template, $context);
        } finally {
            $this->assets->reset();
        }
    }

    /** @param array<string, mixed> $attachments
     *  @param array<string, mixed> $variables
     */
    public function renderResponse(string $template, array $attachments = [], array $variables = [], int $status = Response::HTTP_OK): Response
    {
        try {
            if (array_diff(array_keys($attachments), ['libraries']) !== []) {
                throw new \InvalidArgumentException('Only libraries may be attached to a response; direct css and js assets are forbidden.');
            }
            $libraries = $attachments['libraries'] ?? [];
            if (!is_array($libraries) || !array_is_list($libraries)) {
                throw new \InvalidArgumentException('Response libraries must be a list.');
            }
            foreach ($libraries as $library) {
                if (!is_string($library)) {
                    throw new \InvalidArgumentException('Response library IDs must be strings.');
                }
                $this->assets->attachLibrary($library);
            }
            $this->assets->prepare();

            return new Response($this->twig->render($template, $variables), $status, ['Content-Type' => 'text/html; charset=UTF-8']);
        } finally {
            $this->assets->reset();
        }
    }
}
