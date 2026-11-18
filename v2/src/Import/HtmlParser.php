<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Import;

use Dom\Comment;
use Dom\DocumentType;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\HTMLElement;
use Dom\Node as NativeNode;
use Dom\Text;
use DOMComment;
use DOMDocument;
use DOMDocumentType;
use DOMElement;
use DOMNode;
use DOMText;
use MarkupCarve\Carve\Converter\Html\DomLoader;

/**
 * C2-M-002, C2-M-003: parses HTML with the parser of the 0.1 importer: `Dom\HTMLDocument` (the HTML Standard's
 * algorithm) where PHP has it, the libxml `DOMDocument` otherwise, and copies the result into one tree of `HtmlNode`.
 */
final class HtmlParser
{
    /**
     * @var string
     */
    private const XHTML = 'http://www.w3.org/1999/xhtml';

    /**
     * @var string
     */
    private const LEGACY_ROOT = 'carve-import-root';

    public static function available(): bool
    {
        return class_exists(DOMDocument::class);
    }

    public static function html5(): bool
    {
        return class_exists(HTMLDocument::class);
    }

    /**
     * C2-M-002: a document starts, after ASCII whitespace and comments, with `<!doctype` or `<html`, without case.
     */
    public static function isDocument(string $html): bool
    {
        return preg_match('/\A(?:[ \t\n\f\r]|<!--.*?-->)*(?:<!doctype|<html)/is', $html) === 1;
    }

    /**
     * @param string $html
     * @param bool $legacy Use the libxml parser even where `Dom\HTMLDocument` exists.
     *
     * @return \MarkupCarve\Carve2\Import\HtmlNode The root: for a document the doctype and `<html>`, for a fragment its nodes.
     */
    public static function parse(string $html, bool $legacy = false): HtmlNode
    {
        $document = self::isDocument($html);
        if (!$legacy && self::html5()) {
            return $document ? self::html5Document($html) : self::html5Fragment($html);
        }

        return self::legacy($html, $document);
    }

    private static function html5Document(string $html): HtmlNode
    {
        $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $root = new HtmlNode(HtmlNode::ROOT);
        self::copy($doc, $root);

        return $root;
    }

    private static function html5Fragment(string $html): HtmlNode
    {
        $doc = HTMLDocument::createEmpty();
        $body = $doc->createElement('body');
        $body->innerHTML = $html;
        $root = new HtmlNode(HtmlNode::ROOT);
        self::copy($body, $root);

        return $root;
    }

    private static function copy(NativeNode $source, HtmlNode $target): void
    {
        $pending = [[$source, $target]];
        while ($pending !== []) {
            [$from, $to] = array_pop($pending);
            foreach ($from->childNodes as $child) {
                $node = self::node($child);
                if ($node === null) {
                    continue;
                }
                $to->append($node);
                if ($child instanceof HTMLElement && $child->localName === 'template' && $child->namespaceURI === self::XHTML) {
                    $node->content = self::templateContent($child->innerHTML);
                } elseif ($child instanceof Element) {
                    $pending[] = [$child, $node];
                }
            }
        }
    }

    private static function node(NativeNode $child): ?HtmlNode
    {
        if ($child instanceof Element) {
            $attrs = [];
            foreach ($child->attributes as $attribute) {
                $attrs[$attribute->name] = $attribute->value;
            }
            $foreign = $child->namespaceURI !== self::XHTML;

            return new HtmlNode(HtmlNode::ELEMENT, $foreign ? $child->localName : strtolower($child->localName), $attrs, $foreign);
        }
        if ($child instanceof Text) {
            return new HtmlNode(HtmlNode::TEXT, $child->data);
        }
        if ($child instanceof Comment) {
            return new HtmlNode(HtmlNode::COMMENT, $child->data);
        }

        return $child instanceof DocumentType ? new HtmlNode(HtmlNode::DOCTYPE, $child->name) : null;
    }

    /**
     * C2-H-183: the contents of a template, parsed again from their serialization as a fragment.
     *
     * @param string $inner
     *
     * @return array<\MarkupCarve\Carve2\Import\HtmlNode>
     */
    private static function templateContent(string $inner): array
    {
        $holder = self::html5Fragment($inner);
        foreach ($holder->children as $child) {
            $child->parent = null;
        }

        return $holder->children;
    }

    private static function legacy(string $html, bool $document): HtmlNode
    {
        // The HTML input stream turns CR LF and a lone CR into LF before tokenizing; libxml keeps them.
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if ($document) {
                $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_PARSEHUGE);
            } else {
                $wrapped = '<?xml encoding="UTF-8"><' . self::LEGACY_ROOT . '>' . $html . '</' . self::LEGACY_ROOT . '>';
                $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_PARSEHUGE);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        DomLoader::impliedTableBodies($dom);
        $root = new HtmlNode(HtmlNode::ROOT);
        $source = $document ? $dom : $dom->getElementsByTagName(self::LEGACY_ROOT)->item(0);
        if ($source !== null) {
            $synthetic = null;
            if ($document) {
                foreach ($dom->childNodes as $child) {
                    if ($child instanceof DOMComment && $child->data === '?xml encoding="UTF-8"') {
                        $synthetic = $child;

                        break;
                    }
                }
            }
            self::copyLegacy($source, $root, !$document, $synthetic);
        }

        return $root;
    }

    /**
     * @param \DOMNode $source
     * @param \MarkupCarve\Carve2\Import\HtmlNode $target
     * @param bool $fragment
     * @param \DOMComment|null $synthetic The declaration inserted only to select UTF-8 in the libxml fallback.
     *
     * @return void
     */
    private static function copyLegacy(DOMNode $source, HtmlNode $target, bool $fragment = false, ?DOMComment $synthetic = null): void
    {
        $pending = [[$source, $target]];
        while ($pending !== []) {
            [$from, $to] = array_pop($pending);
            foreach ($from->childNodes as $child) {
                if ($child === $synthetic) {
                    continue;
                }
                // HTML: in a body fragment an `<html>` or `<body>` start tag is ignored, its content stays in place.
                if ($fragment && $child instanceof DOMElement && in_array(strtolower($child->tagName), ['html', 'body'], true)) {
                    $pending[] = [$child, $to];

                    continue;
                }
                $node = self::legacyNode($child);
                if ($node === null) {
                    continue;
                }
                $to->append($node);
                if ($child instanceof DOMElement && $node->name === 'template') {
                    // libxml has no template contents: the children stand in for them.
                    $holder = new HtmlNode(HtmlNode::ROOT);
                    self::copyLegacy($child, $holder);
                    foreach ($holder->children as $inner) {
                        $inner->parent = null;
                    }
                    $node->content = $holder->children;
                } elseif ($child instanceof DOMElement) {
                    $pending[] = [$child, $node];
                }
            }
        }
    }

    private static function legacyNode(DOMNode $child): ?HtmlNode
    {
        if ($child instanceof DOMElement) {
            $attrs = [];
            foreach ($child->attributes as $attribute) {
                $attrs[strtolower($attribute->name)] = (string)$attribute->value;
            }

            return new HtmlNode(HtmlNode::ELEMENT, strtolower($child->localName ?? $child->tagName), $attrs);
        }
        if ($child instanceof DOMText) {
            return new HtmlNode(HtmlNode::TEXT, str_replace("\0", "\u{FFFD}", $child->data));
        }
        if ($child instanceof DOMComment) {
            return new HtmlNode(HtmlNode::COMMENT, $child->data);
        }

        return $child instanceof DOMDocumentType ? new HtmlNode(HtmlNode::DOCTYPE, $child->name) : null;
    }
}
