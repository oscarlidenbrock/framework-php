<?php

declare(strict_types=1);

namespace Module\Example\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ExampleController
{
    public function __construct(private readonly string $message)
    {
    }

    public function __invoke(Request $request): Response
    {
        return new Response($this->message, headers: ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
