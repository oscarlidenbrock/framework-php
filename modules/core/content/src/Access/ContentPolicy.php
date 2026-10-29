<?php

declare(strict_types=1);

namespace Module\Content\Access;

use Framework\Security\AccessDeniedException;
use Framework\Security\RequestSecurityInterface;
use Module\Content\Storage\ContentRecord;

final class ContentPolicy
{
    public function __construct(private readonly ?RequestSecurityInterface $security = null)
    {
    }

    public function inRequest(): bool
    {
        return $this->security?->active() ?? false;
    }

    public function can(string $permission): bool
    {
        return $this->security?->can($permission) ?? false;
    }

    public function require(string $permission): void
    {
        if (!$this->can($permission)) {
            throw new AccessDeniedException('Access denied.');
        }
    }

    public function identityId(): ?int
    {
        return $this->security?->identity()?->id();
    }

    public function canEdit(ContentRecord $record): bool
    {
        return $this->can('content.edit_any') || ($this->identityId() !== null && $record->get('owner') === $this->identityId() && $this->can('content.edit_own'));
    }

    public function canDelete(ContentRecord $record): bool
    {
        return $this->can('content.delete_any') || ($this->identityId() !== null && $record->get('owner') === $this->identityId() && $this->can('content.delete_own'));
    }

    public function requireEdit(ContentRecord $record): void
    {
        if (!$this->canEdit($record)) {
            throw new AccessDeniedException('Access denied.');
        }
    }

    public function requireDelete(ContentRecord $record): void
    {
        if (!$this->canDelete($record)) {
            throw new AccessDeniedException('Access denied.');
        }
    }
}
