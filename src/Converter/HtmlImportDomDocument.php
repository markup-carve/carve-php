<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use DOMDocument;
use DOMElement;

/**
 * @internal
 */
final class HtmlImportDomDocument extends DOMDocument
{
    /**
     * @var int
     */
    public const MAX_DEPTH = 512;

    public function __construct()
    {
        parent::__construct('1.0', 'UTF-8');
        $this->registerNodeClass(DOMElement::class, HtmlImportDomElement::class);
    }
}
