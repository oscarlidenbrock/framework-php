<?php

declare(strict_types=1);

namespace Module\User\Controller;

use Framework\Template\TemplateManager;
use Module\User\AccountManager;
use Module\User\SecurityManager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class RegisterController
{
    public function __construct(private readonly AccountManager $accounts, private readonly SecurityManager $security, private readonly TemplateManager $templates, private readonly UrlGeneratorInterface $urls)
    {
    }

    public function __invoke(Request $request): Response
    {
        if (!$this->accounts->registrationEnabled()) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }
        if ($request->isMethod('GET')) {
            return $this->templates->renderResponse('@user/register.html.twig', variables: ['csrf' => $this->security->csrfToken(), 'error' => null, 'action' => $this->urls->generate('user.register')]);
        }
        $submitted = $request->request->all();
        if (!$this->security->validateCsrf($submitted['_csrf'] ?? null)) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }
        if (!is_string($submitted['email'] ?? null) || !is_string($submitted['password'] ?? null)) {
            return $this->invalid();
        }
        try {
            $this->accounts->register($submitted['email'], $submitted['password']);
        } catch (\Throwable) {
            return $this->invalid();
        }
        return new RedirectResponse($this->urls->generate('user.login'));
    }

    private function invalid(): Response
    {
        return $this->templates->renderResponse('@user/register.html.twig', variables: ['csrf' => $this->security->csrfToken(), 'error' => 'Registration failed.', 'action' => $this->urls->generate('user.register')], status: Response::HTTP_BAD_REQUEST);
    }
}
