<?php

declare(strict_types=1);

namespace Module\Content\Controller;

use Framework\Security\AccessDeniedException;
use Framework\Security\CsrfInterface;
use Module\Admin\PageRenderer;
use Module\Content\Access\ContentPolicy;
use Module\Content\Administration\ContentAdministration;
use Module\Content\ContentType\ContentTypeManager;
use Module\Content\Form\ContentForm;
use Module\Content\Form\ContentFormManager;
use Module\Content\Storage\ContentRecord;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Markup;

final class AdministrationController
{
    public function __construct(
        private readonly ContentAdministration $content,
        private readonly ContentFormManager $forms,
        private readonly ContentTypeManager $types,
        private readonly ContentPolicy $policy,
        private readonly ?PageRenderer $pages,
        private readonly ?CsrfInterface $csrf,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($this->pages === null || $this->csrf === null) {
            throw new AccessDeniedException('Administration is unavailable.');
        }
        $route = $request->attributes->get('_route');
        $type = $request->attributes->get('type');
        $id = $request->attributes->get('id');
        try {
            return match ($route) {
                'content.admin.index' => $this->index(),
                'content.admin.list' => $this->list($type, $request),
                'content.admin.create' => $this->form($type, null, $request),
                'content.admin.edit' => $this->form($type, $id, $request),
                'content.admin.delete' => $this->delete($type, $id, $request),
                default => new Response('Not Found', Response::HTTP_NOT_FOUND),
            };
        } catch (\InvalidArgumentException) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }
    }

    private function index(): Response
    {
        $items = [];
        foreach ($this->content->types() as $type) {
            if ($type->schemaStatus === 'applied') {
                $items[] = ['label' => $type->label, 'url' => $this->urls->generate('content.admin.list', ['type' => $type->namespace])];
            }
        }
        return $this->pages->render('@content/admin/types.html.twig', ['types' => $items]);
    }

    private function list(mixed $type, Request $request): Response
    {
        $type = $this->type($type);
        $definition = $this->types->requireType($type);
        $rows = [];
        foreach ($this->content->list($type) as $record) {
            $rows[] = [
                'title' => $record->get('title'), 'published' => $record->get('published'),
                'view_url' => $record->get('published') ? $this->urls->generate('content.public.show', ['type' => $type, 'id' => $record->id()]) : null,
                'edit_url' => $this->policy->canEdit($record) ? $this->urls->generate('content.admin.edit', ['type' => $type, 'id' => $record->id()]) : null,
                'delete_url' => $this->policy->canDelete($record) ? $this->urls->generate('content.admin.delete', ['type' => $type, 'id' => $record->id()]) : null,
            ];
        }
        $notices = ['created' => 'Content created.', 'updated' => 'Content updated.', 'deleted' => 'Content deleted.'];
        $notice = $request->query->get('notice');
        return $this->pages->render('@content/admin/list.html.twig', [
            'label' => $definition->label, 'rows' => $rows, 'new_url' => $this->policy->can('content.create') ? $this->urls->generate('content.admin.create', ['type' => $type]) : null,
            'types_url' => $this->urls->generate('content.admin.index'), 'notice' => is_string($notice) ? ($notices[$notice] ?? null) : null,
        ]);
    }

    private function form(mixed $type, mixed $id, Request $request): Response
    {
        $type = $this->type($type);
        $record = $id === null ? null : $this->content->editable($type, $this->id($id));
        if ($id !== null && $record === null) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }
        if ($record === null) {
            $this->policy->require('content.create');
        }
        $form = $this->forms->build($type, $record, excluded: $this->policy->can('content.publish') ? [] : ['published']);
        $submitted = [];
        $errors = [];
        if ($request->isMethod('POST')) {
            $submitted = $request->request->all();
            if (!$this->csrf->validateCsrf($submitted['_csrf'] ?? null)) {
                return new Response('Forbidden', Response::HTTP_FORBIDDEN);
            }
            unset($submitted['_csrf']);
            if (array_key_exists('published', $submitted) && !$this->policy->can('content.publish')) {
                throw new AccessDeniedException('Access denied.');
            }
            $result = $form->submit($submitted);
            if ($result->isValid()) {
                $this->content->save($result->record);
                $url = $this->urls->generate('content.admin.list', ['type' => $type]);
                return new RedirectResponse($url . '?notice=' . ($record === null ? 'created' : 'updated'));
            }
            $errors = $result->errors;
        }
        return $this->formPage($type, $record, $form, $submitted, $errors);
    }

    /** @param array<string, mixed> $values
     *  @param array<string, string> $errors
     */
    private function formPage(string $type, ?ContentRecord $record, ContentForm $form, array $values, array $errors): Response
    {
        $url = $record === null ? $this->urls->generate('content.admin.create', ['type' => $type]) : $this->urls->generate('content.admin.edit', ['type' => $type, 'id' => $record->id()]);
        return $this->pages->render('@content/admin/form.html.twig', [
            'heading' => $record === null ? 'Add content' : 'Edit content', 'form' => new Markup($form->render($values, $errors), 'UTF-8'),
            'action' => $url, 'csrf' => $this->csrf->csrfToken(), 'has_errors' => $errors !== [],
            'back_url' => $this->urls->generate('content.admin.list', ['type' => $type]),
        ], $errors === [] ? 200 : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function delete(mixed $type, mixed $id, Request $request): Response
    {
        $type = $this->type($type);
        $record = $this->content->deletable($type, $this->id($id));
        if ($record === null) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }
        if ($request->isMethod('POST')) {
            if (!$this->csrf->validateCsrf($request->request->all()['_csrf'] ?? null)) {
                return new Response('Forbidden', Response::HTTP_FORBIDDEN);
            }
            $this->content->delete($record);
            return new RedirectResponse($this->urls->generate('content.admin.list', ['type' => $type]) . '?notice=deleted');
        }
        return $this->pages->render('@content/admin/delete.html.twig', [
            'title' => $record->get('title'), 'action' => $this->urls->generate('content.admin.delete', ['type' => $type, 'id' => $record->id()]),
            'back_url' => $this->urls->generate('content.admin.list', ['type' => $type]), 'csrf' => $this->csrf->csrfToken(),
        ]);
    }

    private function type(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Invalid content type.');
        }
        ContentTypeManager::validateNamespace($value);
        return $value;
    }

    private function id(mixed $value): int
    {
        if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
            throw new \InvalidArgumentException('Invalid content ID.');
        }
        return (int) $value;
    }
}
