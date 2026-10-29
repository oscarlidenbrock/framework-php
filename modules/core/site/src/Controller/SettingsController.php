<?php

declare(strict_types=1);

namespace Module\Site\Controller;

use Framework\Security\AccessDeniedException;
use Framework\Security\CsrfInterface;
use Framework\Security\RequestSecurityInterface;
use Module\Admin\PageRenderer;
use Module\Site\SiteSettings;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SettingsController
{
    public function __construct(
        private readonly SiteSettings $settings,
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
        $details = $this->settings->details();
        $values = ['title' => $details['title']['effective'], 'email' => $details['email']['effective']];
        $reset = ['title' => false, 'email' => false];
        $error = null;
        if ($request->isMethod('POST')) {
            $input = $request->request->all();
            if (!$this->csrf->validateCsrf($input['_csrf'] ?? null)) {
                return new Response('Forbidden', Response::HTTP_FORBIDDEN);
            }
            try {
                if (array_diff(array_keys($input), ['_csrf', 'title', 'email', 'reset_title', 'reset_email']) !== [] || !is_string($input['title'] ?? null) || !is_string($input['email'] ?? null) || isset($input['reset_title']) && $input['reset_title'] !== '1' || isset($input['reset_email']) && $input['reset_email'] !== '1') {
                    throw new \InvalidArgumentException('Invalid settings form input.');
                }
                $values = ['title' => $input['title'], 'email' => $input['email']];
                $reset = ['title' => isset($input['reset_title']), 'email' => isset($input['reset_email'])];
                $this->settings->save([
                    'title' => $reset['title'] ? null : $values['title'],
                    'email' => $reset['email'] ? null : $values['email'],
                ]);
                return new RedirectResponse($this->urls->generate('site.settings.view', ['saved' => 1]));
            } catch (\InvalidArgumentException|\LogicException $exception) {
                $error = $exception->getMessage();
            }
        }
        return $this->pages->render('@site/settings.html.twig', [
            'details' => $details,
            'can_edit' => $this->security?->can('site.settings.edit') ?? false,
            'values' => $values,
            'reset' => $reset,
            'error' => $error,
            'notice' => $request->query->get('saved') === '1' ? 'Settings saved.' : null,
            'csrf' => $this->csrf->csrfToken(),
            'action' => $this->urls->generate('site.settings.edit'),
        ], $error === null ? 200 : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
