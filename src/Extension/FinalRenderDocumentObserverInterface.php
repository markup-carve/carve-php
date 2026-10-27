<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\Node\Document;

/**
 * Receives the document after all beforeRender transforms have run.
 */
interface FinalRenderDocumentObserverInterface
{
    public function observeFinalRenderDocument(Document $document): void;
}
