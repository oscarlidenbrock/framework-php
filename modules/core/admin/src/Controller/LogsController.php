<?php

declare(strict_types=1);

namespace Module\Admin\Controller;

use Framework\Log\LogReader;
use Framework\Security\RequestSecurityInterface;
use Module\Admin\PageRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class LogsController
{
    public function __construct(private LogReader $reader, private PageRenderer $pages, private RequestSecurityInterface $security, private UrlGeneratorInterface $urls)
    {
    }

    public function __invoke(Request $request): Response
    {
        $this->security->requirePermission('admin.logs.view');
        $id = $request->attributes->get('id');
        if (is_string($id)) {
            $entry = $this->reader->detail($id);
            if ($entry === null) {
                return new Response('Not Found', 404);
            }
            return $this->pages->render('@admin/log_detail.html.twig', ['entry' => $entry, 'list_url' => $this->urls->generate('admin.logs')]);
        }
        $channel = $request->query->get('channel');
        $level = $request->query->get('level');
        try {
            $entries = $this->reader->recent($channel, $level);
        } catch (\InvalidArgumentException) {
            return new Response('Bad Request', 400);
        }
        return $this->pages->render('@admin/logs.html.twig', ['entries' => $entries, 'channel' => $channel, 'level' => $level, 'action' => $this->urls->generate('admin.logs'), 'detail_base' => $this->urls->generate('admin.logs')]);
    }
}
