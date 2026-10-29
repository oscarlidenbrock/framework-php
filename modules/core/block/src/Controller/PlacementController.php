<?php

declare(strict_types=1);

namespace Module\Block\Controller;

use Framework\Block\BlockRegistry;
use Framework\Security\CsrfInterface;
use Framework\Theme\ThemeManager;
use Module\Admin\PageRenderer;
use Module\Block\PlacementManager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PlacementController
{
    public function __construct(private readonly PlacementManager $placements, private readonly ThemeManager $themes, private readonly BlockRegistry $blocks, private readonly ?PageRenderer $pages, private readonly ?CsrfInterface $csrf, private readonly UrlGeneratorInterface $urls)
    {
    }

    public function __invoke(Request $request): Response
    {
        if ($this->pages === null || $this->csrf === null) {
            return new Response('Administration unavailable', 403);
        }
        $theme = $this->themes->active()->id;
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->csrf->validateCsrf($request->request->get('_csrf'))) {
                return new Response('Forbidden', 403);
            }
            try {
                $input = $request->request->all('blocks');
                $placements = [];
                foreach ($input as $id => $value) {
                    if (!is_array($value) || !is_string($id) || !is_string($value['region'] ?? null) || !is_string($value['weight'] ?? null) || !preg_match('/^-?\\d+$/', $value['weight'])) {
                        throw new \InvalidArgumentException('Invalid block placement form.');
                    }
                    $placements[] = ['block' => $id, 'region' => $value['region'], 'weight' => (int) $value['weight'], 'enabled' => isset($value['enabled'])];
                }
                $this->placements->save($theme, $placements);
                return new RedirectResponse($this->urls->generate('block.placements', ['saved' => 1]));
            } catch (\InvalidArgumentException|\LogicException $exception) {
                $error = $exception->getMessage();
            }
        }
        $current = [];
        foreach ($this->placements->placements($theme) as $item) {
            $current[$item['block']] = $item;
        }
        return $this->pages->render('@block/placements.html.twig', ['theme' => $theme, 'regions' => $this->themes->regions($theme), 'blocks' => $this->blocks->all(), 'current' => $current, 'csrf' => $this->csrf->csrfToken(), 'action' => $this->urls->generate('block.placements.update'), 'error' => $error, 'saved' => $request->query->get('saved') === '1'], $error === null ? 200 : 422);
    }
}
