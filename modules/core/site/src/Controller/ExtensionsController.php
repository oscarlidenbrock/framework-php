<?php

declare(strict_types=1);

namespace Module\Site\Controller;

use Framework\Security\AccessDeniedException;
use Framework\Security\CsrfInterface;
use Framework\Security\RequestSecurityInterface;
use Module\Admin\PageRenderer;
use Module\Site\InstallationManager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ExtensionsController
{
    public function __construct(
        private readonly InstallationManager $installation,
        private readonly ?PageRenderer $pages,
        private readonly ?CsrfInterface $csrf,
        private readonly UrlGeneratorInterface $urls,
        private readonly ?RequestSecurityInterface $security,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($this->pages === null || $this->csrf === null) {
            throw new AccessDeniedException('Administration is unavailable.');
        }
        $themesPage = str_starts_with((string) $request->attributes->get('_route'), 'site.extensions.themes');
        $route = $themesPage ? 'site.extensions.themes' : 'site.extensions.modules';
        $error = null;
        if ($request->isMethod('POST')) {
            $input = $request->request->all();
            if (!$this->csrf->validateCsrf($input['_csrf'] ?? null)) {
                return new Response('Forbidden', Response::HTTP_FORBIDDEN);
            }
            try {
                if ($themesPage) {
                    if (array_diff(array_keys($input), ['_csrf', 'kind', 'id']) !== [] || !is_string($input['kind'] ?? null) || !is_string($input['id'] ?? null)) {
                        throw new \InvalidArgumentException('Invalid theme selection.');
                    }
                    $this->installation->selectTheme($input['kind'], $input['id']);
                } else {
                    if (array_diff(array_keys($input), ['_csrf', 'action', 'id']) !== [] || !is_string($input['id'] ?? null)) {
                        throw new \InvalidArgumentException('Invalid module action.');
                    }
                    match ($input['action'] ?? null) {
                        'enable' => $this->installation->enable($input['id']),
                        'disable' => $this->installation->disable($input['id']),
                        default => throw new \InvalidArgumentException('Invalid module action.'),
                    };
                }
                return new RedirectResponse($this->urls->generate($route, ['saved' => 1]));
            } catch (\InvalidArgumentException|\LogicException $exception) {
                $error = $exception->getMessage();
            }
        }
        $variables = [
            'selection' => $this->installation->current(),
            'csrf' => $this->csrf->csrfToken(),
            'action' => $this->urls->generate($route . '.update'),
            'can_edit' => $this->security?->can($themesPage ? 'site.themes.edit' : 'site.extensions.edit') ?? false,
            'error' => $error,
            'notice' => $request->query->get('saved') === '1' ? 'Selection saved. It takes effect on the next request.' : null,
        ];
        if ($themesPage) {
            $variables['themes'] = $this->installation->themes();
        } else {
            $variables['modules'] = $this->installation->modules();
        }
        return $this->pages->render($themesPage ? '@site/themes.html.twig' : '@site/modules.html.twig', $variables, $error === null ? 200 : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
