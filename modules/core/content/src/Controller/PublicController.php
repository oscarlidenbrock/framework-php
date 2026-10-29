<?php

declare(strict_types=1);

namespace Module\Content\Controller;

use Framework\Template\TemplateManager;
use Module\Content\Administration\ContentAdministration;
use Module\Content\Display\ContentViewManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Markup;

final class PublicController
{
    public function __construct(private readonly ContentAdministration $content, private readonly ContentViewManager $views, private readonly TemplateManager $templates)
    {
    }

    public function __invoke(Request $request): Response
    {
        $type = $request->attributes->get('type');
        $id = $request->attributes->get('id');
        if (!is_string($type) || !is_string($id) || !ctype_digit($id) || (int) $id < 1) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }
        try {
            $record = $this->content->published($type, (int) $id);
            if ($record === null) {
                return new Response('Not Found', Response::HTTP_NOT_FOUND);
            }
            return $this->templates->renderResponse('@content/public.html.twig', variables: [
                'title' => $record->get('title'), 'content' => new Markup($this->views->render($record), 'UTF-8'),
            ]);
        } catch (\InvalidArgumentException) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }
    }
}
