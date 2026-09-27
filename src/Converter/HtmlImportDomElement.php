<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use DOMElement;
use DOMNode;
use MarkupCarve\Carve\Exception\HtmlImportDepthExceededException;

/**
 * Bound the tree while the HTML5 parser builds it, before its stack scans grow.
 *
 * @internal
 */
final class HtmlImportDomElement extends DOMElement
{
    public function appendChild(DOMNode $node): DOMNode|false
    {
        $this->checkDepth($node);

        return parent::appendChild($node);
    }

    public function insertBefore(DOMNode $node, ?DOMNode $child = null): DOMNode|false
    {
        $this->checkDepth($node);

        return parent::insertBefore($node, $child);
    }

    public function replaceChild(DOMNode $node, DOMNode $child): DOMNode|false
    {
        $this->checkDepth($node);

        return parent::replaceChild($node, $child);
    }

    private function checkDepth(DOMNode $node): void
    {
        $depth = 0;
        for ($parent = $this; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            $depth++;
        }
        $pending = [[$node, $depth]];
        while ($pending !== []) {
            [$current, $level] = array_pop($pending);
            if ($current instanceof DOMElement) {
                $level++;
                if ($level > HtmlImportDomDocument::MAX_DEPTH) {
                    throw new HtmlImportDepthExceededException(HtmlImportDomDocument::MAX_DEPTH);
                }
            }
            foreach ($current->childNodes as $child) {
                $pending[] = [$child, $level];
            }
        }
    }
}
