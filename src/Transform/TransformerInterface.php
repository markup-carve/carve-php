<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Transform;

use MarkupCarve\Carve\Node\Document;

interface TransformerInterface
{
    public function transform(Document $document): Document;
}
