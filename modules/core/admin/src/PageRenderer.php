<?php

declare(strict_types=1);

namespace Module\Admin;

use Framework\Template\TemplateManager;
use Framework\Security\AuthorizationInterface;
use Framework\Security\CsrfInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PageRenderer
{
    public function __construct(
        private readonly TemplateManager $templates,
        private readonly Navigation $navigation,
        private readonly UrlGeneratorInterface $urls,
        private readonly AuthorizationInterface $authorization,
        private readonly CsrfInterface $csrf,
        private readonly string $workspace,
    ) {
    }

    /** @param array<string, mixed> $variables */
    public function render(string $template, array $variables = [], int $status = 200): Response
    {
        $variables['navigation'] = $this->navigation->items();
        $variables['dashboard_url'] = $this->urls->generate('admin.dashboard');
        $variables['logout_action'] = $this->urls->generate('user.logout');
        $variables['logout_csrf'] = $this->csrf->csrfToken();
        $variables['account_email'] = $this->authorization->identity()?->email();
        $variables['workspace'] = $this->workspace;
        return $this->templates->renderResponse($template, variables: $variables, status: $status);
    }
}
