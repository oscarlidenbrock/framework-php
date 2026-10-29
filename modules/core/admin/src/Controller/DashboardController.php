<?php

declare(strict_types=1);

namespace Module\Admin\Controller;

use Module\Admin\Dashboard;
use Module\Admin\PageRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class DashboardController
{
    public function __construct(private readonly Dashboard $dashboard, private readonly PageRenderer $pages)
    {
    }

    public function __invoke(Request $request): Response
    {
        return $this->pages->render('@admin/dashboard.html.twig', ['email' => $this->dashboard->view()]);
    }
}
