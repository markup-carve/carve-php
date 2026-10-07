<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use DOMElement;
use DOMNode;
use SplObjectStorage;

/**
 * @internal
 */
final class HtmlPrecedingLabels
{
    /**
     * @var \SplObjectStorage<\DOMElement, string|null>
     */
    private SplObjectStorage $names;

    public function __construct(DOMNode $parent)
    {
        $this->names = new SplObjectStorage();
        $name = null;
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $this->names[$child] = $name;
            if (strtolower(HtmlDomLoader::elementName($child)) === 'label') {
                $name = trim($child->textContent);
            }
        }
    }

    public function get(DOMElement $node): ?string
    {
        return $this->names[$node] ?? null;
    }
}
