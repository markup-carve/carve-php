<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use Dom\Comment;
use Dom\DocumentType;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use DOMAttr;
use DOMComment;
use DOMDocument;
use DOMDocumentFragment;
use DOMDocumentType;
use DOMElement;
use DOMException;
use DOMNode;
use DOMProcessingInstruction;
use DOMText;
use InvalidArgumentException;
use MarkupCarve\Carve\Exception\HtmlImportDepthExceededException;
use RuntimeException;
use const Dom\HTML_NO_DEFAULT_NS;

final class HtmlDomLoader
{
    /**
     * @var string
     */
    private const ENCODED_NAME = 'CARVE-N-';

    public static function usesHtml5(): bool
    {
        return class_exists(HTMLDocument::class);
    }

    public static function load(string $html): DOMDocument
    {
        if (!class_exists(DOMDocument::class)) {
            throw new RuntimeException('HTML import requires the PHP DOM extension (ext-dom).');
        }
        if (self::usesHtml5()) {
            $native = HTMLDocument::createFromString($html === '' ? ' ' : $html, LIBXML_NOERROR | HTML_NO_DEFAULT_NS, 'UTF-8');
            $document = new DOMDocument('1.0', 'UTF-8');
            self::copyChildren($native, $document, $document);

            return $document;
        }
        // The HTML input stream normalizes CR LF and a lone CR to LF before
        // tokenizing; libxml keeps them (carve-php#2497).
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        $html = self::processingInstructionsAsComments($html);
        $document = new DOMDocument();
        $document->encoding = 'UTF-8';
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    /**
     * Copy the parsed tree without running it through a second HTML parser.
     * XML-incompatible names use placeholders restored by the import helpers.
     */
    private static function copyChildren(Node $source, DOMNode $target, DOMDocument $document, bool $fragmentWhitespace = false): void
    {
        $pending = [[$source, $target, 0]];
        while ($pending !== []) {
            [$sourceParent, $targetParent, $depth] = array_pop($pending);
            foreach ($sourceParent->childNodes as $child) {
                if ($child instanceof Element) {
                    if ($depth >= 512) {
                        throw new HtmlImportDepthExceededException(512);
                    }
                    $namespace = self::elementNamespace($child, $sourceParent, $targetParent);
                    $name = $namespace !== null && str_contains($child->localName, ':')
                        ? self::encodeName($child->localName)
                        : $child->localName;
                    try {
                        $copy = $namespace === null
                            ? $document->createElement($name)
                            : $document->createElementNS($namespace, $name);
                    } catch (DOMException) {
                        $name = self::encodeName($child->localName);
                        $copy = $namespace === null
                            ? $document->createElement($name)
                            : $document->createElementNS($namespace, $name);
                    }
                    foreach ($child->attributes as $attribute) {
                        $attributeName = $attribute->name;
                        if ($attributeName === 'xmlns' || str_starts_with($attributeName, 'xmlns:')) {
                            $attributeName = self::encodeName($attributeName);
                        }
                        try {
                            $copy->setAttribute($attributeName, ($fragmentWhitespace ? self::decodeFragmentWhitespace($attribute->value) : $attribute->value));
                        } catch (DOMException) {
                            $copy->setAttribute(self::encodeName($attribute->name), ($fragmentWhitespace ? self::decodeFragmentWhitespace($attribute->value) : $attribute->value));
                        }
                    }
                    $targetParent->appendChild($copy);
                    $pending[] = [$child, $copy, $depth + 1];
                } elseif ($child instanceof Text) {
                    $targetParent->appendChild($document->createTextNode($fragmentWhitespace ? self::decodeFragmentWhitespace($child->data) : $child->data));
                } elseif ($child instanceof Comment) {
                    $targetParent->appendChild($document->createComment($fragmentWhitespace ? self::decodeFragmentWhitespace($child->data) : $child->data));
                } elseif ($child instanceof DocumentType) {
                    if ($child->name !== '') {
                        try {
                            $doctype = $document->implementation->createDocumentType($child->name, $child->publicId, $child->systemId);
                        } catch (DOMException) {
                            $doctype = $document->implementation->createDocumentType(self::encodeName($child->name), $child->publicId, $child->systemId);
                        }
                        $targetParent->appendChild($doctype);
                    }
                }
            }
        }
    }

    private static function elementNamespace(Element $element, Node $sourceParent, DOMNode $parent): ?string
    {
        // HTML_NO_DEFAULT_NS removes SVG and MathML namespaces too. Restore
        // them from the parsed parent and the HTML integration points.
        $parentNamespace = $parent->namespaceURI;
        $parentName = $sourceParent instanceof Element ? $sourceParent->localName : null;
        $name = $element->localName;
        if ($parentNamespace === 'http://www.w3.org/2000/svg') {
            if (!in_array($parentName, ['foreignObject', 'desc', 'title'], true)) {
                return $parentNamespace;
            }
        } elseif ($parentNamespace === 'http://www.w3.org/1998/Math/MathML') {
            if ($parentName === 'annotation-xml' && $name === 'svg') {
                return 'http://www.w3.org/2000/svg';
            }
            $textIntegration = in_array($parentName, ['mi', 'mo', 'mn', 'ms', 'mtext'], true)
                && !in_array($name, ['mglyph', 'malignmark'], true);
            $htmlIntegration = $parentName === 'annotation-xml' && $sourceParent instanceof Element
                && in_array(strtolower($sourceParent->getAttribute('encoding')), ['text/html', 'application/xhtml+xml'], true);
            if (!$textIntegration && !$htmlIntegration) {
                return $parentNamespace;
            }
        }

        return match ($name) {
            'svg' => 'http://www.w3.org/2000/svg',
            'math' => 'http://www.w3.org/1998/Math/MathML',
            default => $element->namespaceURI,
        };
    }

    public static function isDocument(string $html): bool
    {
        if (!self::usesHtml5()) {
            return preg_match('/^\s*(<!doctype|<html|<body)/i', $html) === 1;
        }

        return preg_match('/\A(?:\s|<!--.*?-->)*(?:<!doctype(?=[\s>])|<(?:html|body)(?=[\s\/>]))/is', $html) === 1;
    }

    public static function fragment(string $html, string $rootName = 'carve-import-root'): DOMDocument
    {
        if (!class_exists(DOMDocument::class)) {
            throw new RuntimeException('HTML import requires the PHP DOM extension (ext-dom).');
        }
        if (!self::usesHtml5()) {
            return self::load('<' . $rootName . '>' . $html . '</' . $rootName . '>');
        }
        if (self::isDocument($html)) {
            $document = self::load($html);
            $element = $document->documentElement;
            $root = $document->createElement($rootName);
            $nodes = iterator_to_array($document->childNodes);
            foreach ($nodes as $node) {
                if ($node === $element) {
                    foreach (iterator_to_array($element->childNodes) as $child) {
                        if ($child->nodeName === 'head' && !$child->hasChildNodes() && !$child->hasAttributes()) {
                            continue;
                        }
                        $root->appendChild($child);
                    }
                } elseif ($node instanceof DOMComment) {
                    $root->appendChild($node);
                }
            }
            if ($element !== null) {
                $document->replaceChild($root, $element);
            }

            return $document;
        }

        // Check visible template depth before creating native template content
        // fragments, whose cleanup can recurse through deeply nested templates.
        if (stripos($html, '<template') !== false) {
            self::checkTemplateDepth($html);
        }
        $native = HTMLDocument::createEmpty('UTF-8');
        $context = $native->createElement('template');
        $context->innerHTML = $html;
        // Native template contents are not exposed through childNodes. Parse
        // their serialization with visible template children. Whitespace stays
        // whitespace, but no leading LF can be consumed a second time.
        $serialized = strtr($context->innerHTML, ["\f" => "\f\f", "\n" => "\f\t", "\r" => "\f "]);
        $visible = HTMLDocument::createFromString('<!doctype html><template>' . $serialized, LIBXML_NOERROR | HTML_NO_DEFAULT_NS, 'UTF-8');
        foreach ($visible->getElementsByTagName('plaintext') as $plaintext) {
            if (!$plaintext->firstChild instanceof Text || !str_ends_with($serialized, $plaintext->firstChild->data)) {
                continue;
            }
            // Plaintext can be foster-parented before a table. Its generated
            // closing tag must not make the second parse swallow those siblings.
            // An EOF marker identifies the generated close among authored ones.
            $marker = 'carve-plaintext-' . bin2hex(random_bytes(16));
            $context->innerHTML = $html . $marker;
            $marked = strtr($context->innerHTML, ["\f" => "\f\f", "\n" => "\f\t", "\r" => "\f "]);
            $end = strpos($marked, $marker);
            if ($end !== false) {
                $textOffset = strlen($serialized) - strlen($plaintext->firstChild->data);
                $openingLength = strlen($visible->saveHtml($plaintext->cloneNode(false))) - strlen('</plaintext>');
                $nameOffset = $textOffset - $openingLength + 1;
                $replacement = 'carve-plaintext-' . bin2hex(random_bytes(16));
                $text = substr($marked, $textOffset, $end - $textOffset);
                $serialized = substr($marked, 0, $nameOffset) . $replacement
                    . substr($marked, $nameOffset + strlen('plaintext'), $textOffset - $nameOffset - strlen('plaintext'))
                    . str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text)
                    . '</' . $replacement . '>'
                    . substr($marked, $end + strlen($marker) + strlen('</plaintext>'));
                $visible = HTMLDocument::createFromString('<!doctype html><template>' . $serialized, LIBXML_NOERROR | HTML_NO_DEFAULT_NS, 'UTF-8');
                $visible->getElementsByTagName($replacement)->item(0)?->rename(null, 'plaintext');
            }

            break;
        }
        $fragment = $visible->getElementsByTagName('template')->item(0);
        $document = new DOMDocument('1.0', 'UTF-8');
        $root = $document->createElement($rootName);
        $document->appendChild($root);
        if ($fragment !== null) {
            self::copyChildren($fragment, $root, $document, true);
        }

        return $document;
    }

    private static function checkTemplateDepth(string $html): void
    {
        // A nonempty body makes frameset tokens inert, as they are in a
        // template fragment. Count templates only; document wrappers do not
        // contribute to the hidden-fragment cleanup recursion.
        $document = HTMLDocument::createFromString('<!doctype html><body>x' . $html, LIBXML_NOERROR | HTML_NO_DEFAULT_NS, 'UTF-8');
        $pending = [[$document, 0]];
        while ($pending !== []) {
            [$parent, $depth] = array_pop($pending);
            foreach ($parent->childNodes as $child) {
                if (!$child instanceof Element) {
                    continue;
                }
                $childDepth = $depth + (int)($child->localName === 'template');
                if ($childDepth > 512) {
                    throw new HtmlImportDepthExceededException(512);
                }
                $pending[] = [$child, $childDepth];
            }
        }
    }

    private static function decodeFragmentWhitespace(string $text): string
    {
        return strtr($text, ["\f\f" => "\f", "\f\t" => "\n", "\f " => "\r"]);
    }

    public static function elementName(DOMElement $element): string
    {
        return self::decodeName($element->localName ?? $element->tagName);
    }

    public static function attributeName(DOMAttr $attribute): string
    {
        return self::decodeName($attribute->name);
    }

    private static function encodeName(string $name): string
    {
        return self::ENCODED_NAME . bin2hex($name);
    }

    private static function decodeName(string $name): string
    {
        if (!str_starts_with($name, self::ENCODED_NAME)) {
            return $name;
        }
        $encoded = substr($name, strlen(self::ENCODED_NAME));
        if (preg_match('/\A(?:[0-9a-f]{2})+\z/', $encoded) !== 1) {
            return $name;
        }
        $decoded = hex2bin($encoded);

        return $decoded === false ? $name : $decoded;
    }

    public static function serialize(DOMNode $node): string
    {
        if (!self::usesHtml5()) {
            $document = $node instanceof DOMDocument ? $node : $node->ownerDocument;
            $html = $document?->saveHTML($node);
            if (is_string($html)) {
                return $html;
            }

            throw new InvalidArgumentException('Cannot serialize this DOM node as HTML.');
        }
        if ($node instanceof DOMText) {
            $parent = $node->parentNode;
            if ($parent instanceof DOMElement && $parent->namespaceURI === null && in_array(self::elementName($parent), ['style', 'script', 'xmp', 'iframe', 'noembed', 'noframes', 'plaintext'], true)) {
                return $node->data;
            }

            return str_replace(['&', '<', '>', "\u{00A0}"], ['&amp;', '&lt;', '&gt;', '&nbsp;'], $node->data);
        }
        if ($node instanceof DOMComment) {
            return '<!--' . $node->data . '-->';
        }
        if ($node instanceof DOMDocumentType) {
            return '<!DOCTYPE ' . self::decodeName($node->name) . '>';
        }
        if ($node instanceof DOMProcessingInstruction) {
            return '<?' . $node->target . ' ' . $node->data . '>';
        }
        if (!$node instanceof DOMElement && !$node instanceof DOMDocument && !$node instanceof DOMDocumentFragment) {
            throw new InvalidArgumentException('Cannot serialize this DOM node as HTML.');
        }
        $out = '';
        $tag = null;
        if ($node instanceof DOMElement) {
            $tag = self::elementName($node);
            $out = '<' . $tag;
            foreach ($node->attributes as $attribute) {
                $out .= ' ' . self::attributeName($attribute) . '="' . str_replace(['&', '"', "\u{00A0}"], ['&amp;', '&quot;', '&nbsp;'], $attribute->value) . '"';
            }
            $out .= '>';
            if ($node->namespaceURI === null && in_array($tag, ['area', 'base', 'basefont', 'bgsound', 'br', 'col', 'embed', 'frame', 'hr', 'img', 'input', 'keygen', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true)) {
                return $out;
            }
            if ($node->namespaceURI === null && in_array($tag, ['pre', 'textarea', 'listing'], true) && $node->firstChild instanceof DOMText && str_starts_with($node->firstChild->data, "\n")) {
                $out .= "\n";
            }
        }
        foreach ($node->childNodes as $child) {
            $out .= self::serialize($child);
        }

        return $tag === null ? $out : $out . '</' . $tag . '>';
    }

    private static function processingInstructionsAsComments(string $html): string
    {
        $length = strlen($html);
        $out = '';
        $copied = 0;
        $offset = 0;
        while ($offset < $length) {
            if ($html[$offset] !== '<') {
                $offset++;

                continue;
            }
            if (substr_compare($html, '<!--', $offset, 4) === 0) {
                $end = strpos($html, '-->', $offset + 4);
                $offset = $end === false ? $length : $end + 3;

                continue;
            }
            if (substr_compare($html, '<?', $offset, 2) === 0) {
                $end = strpos($html, '>', $offset + 2);
                if ($end === false) {
                    break;
                }
                $data = substr($html, $offset + 1, $end - $offset - 1);
                if (!str_contains($data, '--') && !str_ends_with($data, '-')) {
                    $out .= substr($html, $copied, $offset - $copied) . '<!--' . $data . '-->';
                    $copied = $end + 1;
                }
                $offset = $end + 1;

                continue;
            }
            if (preg_match('/\G<([a-z][^ \t\n\r\f\/>]*)/i', $html, $match, 0, $offset) !== 1) {
                $offset++;

                continue;
            }
            $tag = strtolower($match[1]);
            $offset += strlen($match[0]);
            while ($offset < $length && $html[$offset] !== '>') {
                if ($html[$offset++] !== '=') {
                    continue;
                }
                while ($offset < $length && str_contains(" \t\n\r\f", $html[$offset])) {
                    $offset++;
                }
                if ($offset < $length && ($html[$offset] === '"' || $html[$offset] === "'")) {
                    $quote = $html[$offset++];
                    while ($offset < $length && $html[$offset] !== $quote) {
                        $offset++;
                    }
                    if ($offset < $length) {
                        $offset++;
                    }
                } else {
                    while ($offset < $length && !str_contains(" \t\n\r\f>", $html[$offset])) {
                        $offset++;
                    }
                }
            }
            if ($offset < $length) {
                $offset++;
            }
            if ($tag === 'plaintext') {
                break;
            }
            if (
                in_array($tag, [
                    'script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript',
                ], true)
            ) {
                if (preg_match('~</' . $tag . '(?=[ \t\n\r\f/>])~i', $html, $match, PREG_OFFSET_CAPTURE, $offset) !== 1) {
                    break;
                }
                $offset = $match[0][1] + strlen($match[0][0]);
            }
        }

        return $out . substr($html, $copied);
    }
}
