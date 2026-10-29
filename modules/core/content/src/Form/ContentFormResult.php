<?php

declare(strict_types=1);

namespace Module\Content\Form;

use Module\Content\Storage\ContentRecord;

final class ContentFormResult
{
    /** @param array<string, string> $errors */
    public function __construct(
        public readonly ?ContentRecord $record,
        public readonly array $errors,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [] && $this->record !== null;
    }
}
