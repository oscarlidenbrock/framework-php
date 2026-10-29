<?php

declare(strict_types=1);

namespace Module\User\Controller;

use Module\User\SecurityManager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class LogoutController
{
    public function __construct(private readonly SecurityManager $security, private readonly UrlGeneratorInterface $urls)
    {
    }

    public function __invoke(Request $request): Response
    {
        if (!$this->security->validateCsrf($request->request->all()['_csrf'] ?? null)) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }
        $this->security->logout();
        return new RedirectResponse($this->urls->generate('user.login'));
    }
}
