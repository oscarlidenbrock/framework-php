<?php

declare(strict_types=1);

namespace Module\User\Controller;

use Framework\Template\TemplateManager;
use Module\User\SecurityManager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class LoginController
{
    public function __construct(private readonly SecurityManager $security, private readonly TemplateManager $templates, private readonly UrlGeneratorInterface $urls)
    {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('GET')) {
            return $this->templates->renderResponse('@user/login.html.twig', variables: [
                'csrf' => $this->security->csrfToken(),
                'error' => null,
                'action' => $this->urls->generate('user.login'),
                'logout_action' => $this->urls->generate('user.logout'),
                'authenticated' => $this->security->identity() !== null,
            ]);
        }
        $submitted = $request->request->all();
        if (!$this->security->validateCsrf($submitted['_csrf'] ?? null)) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }
        $email = $submitted['email'] ?? null;
        $password = $submitted['password'] ?? null;
        if (is_string($email) && is_string($password) && $this->security->login($email, $password, $request->getClientIp() ?? 'unknown')) {
            return new RedirectResponse($this->urls->generate($this->security->can('admin.access') ? 'admin.dashboard' : 'user.login'));
        }
        return $this->templates->renderResponse('@user/login.html.twig', variables: [
            'csrf' => $this->security->csrfToken(), 'error' => 'Invalid credentials.', 'action' => $this->urls->generate('user.login'), 'logout_action' => $this->urls->generate('user.logout'), 'authenticated' => false,
        ], status: Response::HTTP_UNAUTHORIZED);
    }
}
