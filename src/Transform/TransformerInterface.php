<?php

declare(strict_types=1);

namespace Carve\Transform;

use Carve\Node\Document;

interface TransformerInterface
{
    public function transform(Document $document): Document;
}
