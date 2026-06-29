<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\Node\Document;

/**
 * Optional extension lifecycle hook for syncing state after parse().
 */
interface ParsedDocumentExtensionInterface extends ExtensionInterface
{
    public function afterParse(Document $document): void;
}
