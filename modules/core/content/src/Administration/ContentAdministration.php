<?php

declare(strict_types=1);

namespace Module\Content\Administration;

use Module\Content\Access\ContentPolicy;
use Module\Content\ContentType\ContentTypeManager;
use Module\Content\Storage\ContentRecord;
use Module\Content\Storage\ContentStorage;

final class ContentAdministration
{
    public function __construct(
        private readonly ContentTypeManager $types,
        private readonly ContentStorage $storage,
        private readonly ContentPolicy $policy,
    ) {
    }

    /** @return list<\Module\Content\ContentType\ContentType> */
    public function types(): array
    {
        $this->policy->require('content.view_admin');
        return $this->types->all();
    }

    /** @return list<ContentRecord> */
    public function list(string $type): array
    {
        $this->policy->require('content.view_admin');
        return $this->storage->findBy($type, []);
    }

    public function editable(string $type, int $id): ?ContentRecord
    {
        $record = $this->storage->find($type, $id);
        if ($record !== null) {
            $this->policy->requireEdit($record);
        }
        return $record;
    }

    public function deletable(string $type, int $id): ?ContentRecord
    {
        $record = $this->storage->find($type, $id);
        if ($record !== null) {
            $this->policy->requireDelete($record);
        }
        return $record;
    }

    public function save(ContentRecord $record): void
    {
        if ($record->id() === null) {
            $this->policy->require('content.create');
            if ($record->get('published') === true) {
                $this->policy->require('content.publish');
            }
        } else {
            $existing = $this->storage->find($record->type, $record->id(), $record->connection);
            if ($existing === null) {
                throw new \InvalidArgumentException('Content not found.');
            }
            $this->policy->requireEdit($existing);
            if ($record->get('published') !== $existing->get('published')) {
                $this->policy->require('content.publish');
            }
        }
        $this->storage->save($record);
    }

    public function delete(ContentRecord $record): void
    {
        $this->policy->requireDelete($record);
        $this->storage->delete($record);
    }

    public function published(string $type, int $id): ?ContentRecord
    {
        $record = $this->storage->find($type, $id);
        return $record !== null && $record->get('published') === true ? $record : null;
    }
}
