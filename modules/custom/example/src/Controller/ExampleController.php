<?php

declare(strict_types=1);

namespace Module\Example\Controller;

use Framework\Template\TemplateManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ExampleController
{
    public function __construct(
        private readonly TemplateManager $templates,
        private readonly string $title,
        private readonly string $message,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        return $this->templates->renderResponse(
            '@example/index.html.twig',
            ['libraries' => ['example/base']],
            ['title' => $this->title, 'message' => $this->message],
        );
    }
}
