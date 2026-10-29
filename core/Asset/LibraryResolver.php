<?php

declare(strict_types=1);

namespace Framework\Asset;

final readonly class LibraryResolver
{
    public function __construct(private LibraryRegistry $registry)
    {
    }

    /** @param list<string> $ids
     *  @return list<Library>
     */
    public function resolve(array $ids): array
    {
        $state = [];
        $ordered = [];
        $scriptsById = [];
        $visit = function (string $id) use (&$visit, &$state, &$ordered, &$scriptsById): void {
            if (($state[$id] ?? null) === 'visiting') {
                throw new \InvalidArgumentException("Circular library dependency involving {$id}");
            }
            if (($state[$id] ?? null) === 'done') {
                return;
            }
            $library = $this->registry->get($id);
            $state[$id] = 'visiting';
            $dependencyScripts = [];
            foreach ($library->dependencies as $dependency) {
                $visit($dependency);
                array_push($dependencyScripts, ...$scriptsById[$dependency]);
            }
            foreach ($dependencyScripts as $dependencyScript) {
                foreach ($library->assets['js'] as $script) {
                    $dependencyDeferred = $dependencyScript->attributes['defer'] || ($dependencyScript->attributes['type'] ?? null) === 'module';
                    $scriptDeferred = $script->attributes['defer'] || ($script->attributes['type'] ?? null) === 'module';
                    if ($dependencyScript->attributes['async'] || $script->attributes['async'] || !$dependencyDeferred || !$scriptDeferred || $dependencyScript->position !== $script->position && !($dependencyScript->position === 'head' && $script->position === 'body')) {
                        throw new \InvalidArgumentException("Library {$id} cannot guarantee script order after its dependencies");
                    }
                }
            }
            $scriptsById[$id] = [...$dependencyScripts, ...$library->assets['js']];
            $state[$id] = 'done';
            $ordered[] = $library;
        };
        foreach ($ids as $id) {
            $visit($id);
        }

        return $ordered;
    }
}
