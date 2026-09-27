<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use DOMComment;
use DOMDocument;
use DOMDocumentFragment;
use DOMDocumentType;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;
use DOMText;
use InvalidArgumentException;
use MensBeam\HTML\Parser;
use MensBeam\HTML\Parser\Config;
use RuntimeException;

final class HtmlDomLoader
{
    private static function parserConfig(): Config
    {
        $config = new Config();
        $config->documentClass = HtmlImportDomDocument::class;

        return $config;
    }

    public static function load(string $html): DOMDocument
    {
        if (!class_exists(DOMDocument::class)) {
            throw new RuntimeException('HTML import requires the PHP DOM extension (ext-dom).');
        }

        $document = Parser::parse($html, 'UTF-8', self::parserConfig())->document;
        $document->encoding = 'UTF-8';

        self::normalizeImportedDom($document);

        return $document;
    }

    public static function isDocument(string $html): bool
    {
        return preg_match('/\A(?:\s|<!--.*?-->)*(?:<!doctype(?=[\s>])|<(?:html|body)(?=[\s\/>]))/is', $html) === 1;
    }

    public static function fragment(string $html, string $rootName = 'carve-import-root'): DOMDocument
    {
        if (!class_exists(DOMDocument::class)) {
            throw new RuntimeException('HTML import requires the PHP DOM extension (ext-dom).');
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

        $document = new DOMDocument('1.0', 'UTF-8');
        $root = $document->createElement($rootName);
        $document->appendChild($root);
        $context = $document->createElement('template');
        $fragment = Parser::parseFragment($context, Parser::NO_QUIRKS_MODE, $html, 'UTF-8', self::parserConfig());
        if ($fragment->hasChildNodes()) {
            $root->appendChild($fragment);
        }

        self::normalizeImportedDom($document);

        return $document;
    }

    /**
     * Keep foreign-element names unprefixed after DOM fragment import.
     */
    private static function normalizeImportedDom(DOMDocument $document): void
    {
        foreach ($document->getElementsByTagName('*') as $element) {
            if (in_array($element->namespaceURI, [Parser::SVG_NAMESPACE, Parser::MATHML_NAMESPACE], true)) {
                $element->prefix = '';
            }
        }
    }

    public static function serialize(DOMNode $node): string
    {
        if (
            $node instanceof DOMElement || $node instanceof DOMText || $node instanceof DOMComment
            || $node instanceof DOMDocument || $node instanceof DOMDocumentFragment
            || $node instanceof DOMDocumentType || $node instanceof DOMProcessingInstruction
        ) {
            $copy = $node->cloneNode(true);
            $stack = [$copy];
            while ($stack !== []) {
                $current = array_pop($stack);
                if (
                    $current instanceof DOMElement
                    && in_array(strtolower($current->tagName), ['pre', 'textarea', 'listing'], true)
                    && $current->firstChild instanceof DOMText
                    && str_starts_with($current->firstChild->data, "\n")
                ) {
                    $current->firstChild->data = "\n" . $current->firstChild->data;
                }
                foreach ($current->childNodes as $child) {
                    $stack[] = $child;
                }
            }

            return Parser::serialize($copy);
        }

        throw new InvalidArgumentException('Cannot serialize this DOM node as HTML.');
    }
}
