<?php

declare(strict_types=1);

namespace Module\Content\Controller;

use Framework\Field\FieldDefinition;
use Framework\Security\AccessDeniedException;
use Framework\Security\CsrfInterface;
use Module\Admin\PageRenderer;
use Module\Content\Access\ContentPolicy;
use Module\Content\Administration\ContentStructure;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class StructureController
{
    public function __construct(
        private readonly ContentStructure $structure,
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
        try {
            $connection = $this->structure->connection($this->connectionName($request));
            $type = $request->attributes->get('type');
            $field = $request->attributes->get('field');
            return match ($request->attributes->get('_route')) {
                'content.structure.index' => $this->index($connection, $request),
                'content.structure.new' => $this->create($connection, $request),
                'content.structure.show' => $this->show($this->segment($type), $connection, $request),
                'content.structure.field_new' => $this->fieldForm($this->segment($type), null, $connection, $request),
                'content.structure.field_edit' => $this->fieldForm($this->segment($type), $this->segment($field), $connection, $request),
                'content.structure.schema' => $this->schema($this->segment($type), $connection),
                'content.structure.schema_apply' => $this->apply($this->segment($type), $connection, $request),
                default => new Response('Not Found', Response::HTTP_NOT_FOUND),
            };
        } catch (\InvalidArgumentException) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }
    }

    private function index(string $connection, Request $request): Response
    {
        $types = [];
        foreach ($this->structure->all($connection) as $type) {
            $types[] = [
                'label' => $type->label,
                'namespace' => $type->namespace,
                'status' => $type->schemaStatus,
                'url' => $this->url('content.structure.show', ['type' => $type->namespace], $connection),
            ];
        }
        return $this->pages->render('@content/admin/structure/index.html.twig', [
            'types' => $types,
            'connections' => $this->structure->connections(),
            'connection' => $connection,
            'structure_index_url' => $this->urls->generate('content.structure.index'),
            'new_url' => $this->policy->can('content.structure_manage') ? $this->url('content.structure.new', [], $connection) : null,
            'notice' => $request->query->get('notice') === 'created' ? 'Content type created. Review and apply its schema before adding content.' : null,
        ]);
    }

    private function create(string $connection, Request $request): Response
    {
        $values = ['namespace' => '', 'label' => '', 'description' => ''];
        $error = null;
        if ($request->isMethod('POST')) {
            $submitted = $request->request->all();
            if (!$this->csrf->validateCsrf($submitted['_csrf'] ?? null)) {
                return new Response('Forbidden', Response::HTTP_FORBIDDEN);
            }
            try {
                $this->onlyKeys($submitted, ['_csrf', 'namespace', 'label', 'description']);
                $values = [
                    'namespace' => $this->string($submitted, 'namespace'),
                    'label' => $this->string($submitted, 'label'),
                    'description' => $this->string($submitted, 'description'),
                ];
                $type = $this->structure->create($values['namespace'], $values['label'], $values['description'], $connection);
                return new RedirectResponse($this->url('content.structure.show', ['type' => $type->namespace, 'notice' => 'created'], $connection));
            } catch (\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }
        return $this->pages->render('@content/admin/structure/type_form.html.twig', [
            'values' => $values, 'error' => $error, 'csrf' => $this->csrf->csrfToken(),
            'action' => $this->url('content.structure.new', [], $connection),
            'back_url' => $this->url('content.structure.index', [], $connection),
            'connection' => $connection,
        ], $error === null ? 200 : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function show(string $namespace, string $connection, Request $request): Response
    {
        $type = $this->structure->type($namespace, $connection);
        $fields = [];
        foreach ($this->structure->fields($namespace, $connection) as $field) {
            $fields[] = [
                'name' => $field->name, 'label' => $field->label, 'type' => $field->type,
                'system' => $field->system, 'required' => $field->required,
                'settings' => $field->settings,
                'edit_url' => $this->policy->can('content.structure_manage') && (!$field->system || $field->name === 'title')
                    ? $this->url('content.structure.field_edit', ['type' => $namespace, 'field' => $field->name], $connection) : null,
            ];
        }
        $notice = match ($request->query->get('notice')) {
            'created' => 'Content type created. Review and apply its schema before adding content.',
            'field_added' => 'Field definition saved. The schema is pending.',
            'field_updated' => 'Field definition updated. Review the schema diff.',
            'applied' => 'Schema applied successfully. Content can now use the new fields.',
            default => null,
        };
        return $this->pages->render('@content/admin/structure/show.html.twig', [
            'type' => $type, 'fields' => $fields, 'notice' => $notice,
            'back_url' => $this->url('content.structure.index', [], $connection),
            'add_url' => $this->policy->can('content.structure_manage') ? $this->url('content.structure.field_new', ['type' => $namespace], $connection) : null,
            'schema_url' => $this->url('content.structure.schema', ['type' => $namespace], $connection),
            'content_url' => $type->schemaStatus === 'applied' && $this->policy->can('content.view_admin') ? $this->url('content.admin.list', ['type' => $namespace], $connection) : null,
        ]);
    }

    private function fieldForm(string $namespace, ?string $fieldName, string $connection, Request $request): Response
    {
        $type = $this->structure->type($namespace, $connection);
        $existing = $fieldName === null ? null : ($this->structure->fields($namespace, $connection)[$fieldName] ?? throw new \InvalidArgumentException('Unknown field.'));
        if ($existing?->system && $fieldName !== 'title') {
            throw new AccessDeniedException('System field constraints cannot be changed here.');
        }
        $values = $existing === null ? $this->emptyFieldValues() : $this->fieldValues($existing);
        $error = null;
        if ($request->isMethod('POST')) {
            $submitted = $request->request->all();
            if (!$this->csrf->validateCsrf($submitted['_csrf'] ?? null)) {
                return new Response('Forbidden', Response::HTTP_FORBIDDEN);
            }
            $values = array_merge($values, array_filter($submitted, static fn (string|int $key): bool => $key !== '_csrf', ARRAY_FILTER_USE_KEY));
            if ($existing === null || !$existing->system) {
                $values['required'] = $submitted['required'] ?? false;
            }
            try {
                $definition = $this->fieldInput($submitted, $existing);
                if ($existing === null) {
                    $this->structure->addField($namespace, $definition, $connection);
                } else {
                    $this->structure->updateField($namespace, $existing->name, $definition, $connection);
                }
                return new RedirectResponse($this->url('content.structure.show', ['type' => $namespace, 'notice' => $existing === null ? 'field_added' : 'field_updated'], $connection));
            } catch (\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }
        return $this->pages->render('@content/admin/structure/field_form.html.twig', [
            'type' => $type, 'existing' => $existing, 'values' => $values, 'error' => $error,
            'field_types' => $this->structure->fieldTypes(), 'csrf' => $this->csrf->csrfToken(),
            'action' => $existing === null ? $this->url('content.structure.field_new', ['type' => $namespace], $connection) : $this->url('content.structure.field_edit', ['type' => $namespace, 'field' => $existing->name], $connection),
            'back_url' => $this->url('content.structure.show', ['type' => $namespace], $connection),
        ], $error === null ? 200 : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function schema(string $namespace, string $connection, ?string $error = null, int $status = 200): Response
    {
        $type = $this->structure->type($namespace, $connection);
        $changes = $this->structure->diff($namespace, $connection);
        $safe = !in_array(true, array_column($changes, 'destructive'), true);
        return $this->pages->render('@content/admin/structure/schema.html.twig', [
            'type' => $type, 'changes' => $changes, 'safe' => $safe, 'error' => $error,
            'csrf' => $this->csrf->csrfToken(),
            'apply_url' => $this->policy->can('content.schema_apply') && $safe && ($changes !== [] || $type->schemaStatus !== 'applied') ? $this->url('content.structure.schema_apply', ['type' => $namespace], $connection) : null,
            'back_url' => $this->url('content.structure.show', ['type' => $namespace], $connection),
        ], $status);
    }

    private function apply(string $namespace, string $connection, Request $request): Response
    {
        $submitted = $request->request->all();
        if (!$this->csrf->validateCsrf($submitted['_csrf'] ?? null)) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }
        try {
            $this->onlyKeys($submitted, ['_csrf']);
            $this->structure->applySafe($namespace, $connection);
            return new RedirectResponse($this->url('content.structure.show', ['type' => $namespace, 'notice' => 'applied'], $connection));
        } catch (\LogicException $exception) {
            return $this->schema($namespace, $connection, $exception->getMessage(), Response::HTTP_CONFLICT);
        } catch (\Doctrine\DBAL\Exception) {
            return $this->pages->render('@content/admin/structure/apply_error.html.twig', [
                'back_url' => $this->url('content.structure.schema', ['type' => $namespace], $connection),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** @param array<string, mixed> $input
     *  @param list<string> $allowed
     */
    private function onlyKeys(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new \InvalidArgumentException('Unknown form input.');
        }
    }

    /** @param array<string, mixed> $input */
    private function string(array $input, string $key, string $fallback = ''): string
    {
        $value = $input[$key] ?? $fallback;
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Invalid {$key} value.");
        }
        return $value;
    }

    /** @param array<string, mixed> $input */
    private function integer(array $input, string $key, ?int $fallback = null): ?int
    {
        $value = $input[$key] ?? null;
        if ($value === null || $value === '') {
            return $fallback;
        }
        if (!is_string($value) || !preg_match('/^-?(?:0|[1-9][0-9]*)$/', $value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException("Invalid {$key} value.");
        }
        return (int) $value;
    }

    /** @param array<string, mixed> $input */
    private function checkbox(array $input, string $key): bool
    {
        $value = $input[$key] ?? null;
        if ($value !== null && $value !== '1') {
            throw new \InvalidArgumentException("Invalid {$key} value.");
        }
        return $value === '1';
    }

    /** @return array<string, mixed> */
    private function emptyFieldValues(): array
    {
        return ['name' => '', 'label' => '', 'type' => 'string', 'required' => false, 'default_mode' => 'none', 'default' => '', 'length' => '255', 'precision' => '10', 'scale' => '0', 'weight' => '0'];
    }

    /** @return array<string, mixed> */
    private function fieldValues(FieldDefinition $field): array
    {
        return [
            'name' => $field->name, 'label' => $field->label, 'type' => $field->type,
            'default_mode' => $field->default === null ? 'none' : 'value',
            'required' => $field->required, 'default' => is_bool($field->default) ? ($field->default ? 'true' : 'false') : (is_scalar($field->default) ? (string) $field->default : ''),
            'length' => (string) ($field->settings['length'] ?? 255),
            'precision' => (string) ($field->settings['precision'] ?? 10),
            'scale' => (string) ($field->settings['scale'] ?? 0), 'weight' => (string) $field->weight,
        ];
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    private function fieldInput(array $input, ?FieldDefinition $existing): array
    {
        $this->onlyKeys($input, ['_csrf', 'name', 'label', 'type', 'required', 'default_mode', 'default', 'length', 'precision', 'scale', 'weight']);
        $name = $existing === null ? $this->string($input, 'name') : $existing->name;
        if ($existing !== null && array_key_exists('name', $input) && $input['name'] !== $existing->name) {
            throw new \InvalidArgumentException('Field names cannot be changed.');
        }
        $type = $existing?->system ? $existing->type : $this->string($input, 'type');
        if ($existing?->system && $this->string($input, 'type', $existing->type) !== $existing->type) {
            throw new \InvalidArgumentException('System field name and type cannot be changed.');
        }
        $settings = match ($type) {
            'string' => ['length' => $this->integer($input, 'length', 255)],
            'decimal' => ['precision' => $this->integer($input, 'precision', 10), 'scale' => $this->integer($input, 'scale', 0)],
            default => [],
        };
        $result = ['label' => $this->string($input, 'label'), 'settings' => $settings];
        if ($existing?->system) {
            return $result;
        }
        $result['type'] = $type;
        $result['required'] = $this->checkbox($input, 'required');
        $result['weight'] = $this->integer($input, 'weight', 0);
        $default = $this->string($input, 'default');
        $mode = $this->string($input, 'default_mode', 'none');
        if (!in_array($mode, ['none', 'value'], true)) {
            throw new \InvalidArgumentException('Invalid default mode.');
        }
        $result['default'] = match (true) {
            $mode === 'none' => null,
            $type === 'boolean' => match (strtolower($default)) {
                'true', '1' => true, 'false', '0' => false,
                default => throw new \InvalidArgumentException('Boolean default must be true or false.'),
            },
            $type === 'integer' || $type === 'bigint' => filter_var($default, FILTER_VALIDATE_INT) !== false ? (int) $default : throw new \InvalidArgumentException('Invalid integer default.'),
            $type === 'float' => is_numeric($default) ? (float) $default : throw new \InvalidArgumentException('Invalid float default.'),
            default => $default,
        };
        if ($existing === null) {
            $result['name'] = $name;
        }
        return $result;
    }

    private function connectionName(Request $request): ?string
    {
        $name = $request->query->all()['connection'] ?? null;
        if ($name !== null && !is_string($name)) {
            throw new \InvalidArgumentException('Invalid connection.');
        }
        return $name;
    }

    private function segment(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Invalid route parameter.');
        }
        return $value;
    }

    /** @param array<string, string> $parameters */
    private function url(string $route, array $parameters, string $connection): string
    {
        return $this->urls->generate($route, [...$parameters, 'connection' => $connection]);
    }
}
