<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use DOMDocument;
use DOMElement;
use MarkupCarve\Carve\Node;
use RuntimeException;

/**
 * C2-X-059 and C2-X-060: claim an image fence and sanitize its SVG when rendering.
 */
final class ImgFence
{
    /**
     * @var string
     */
    private const SVG_NS = 'http://www.w3.org/2000/svg';

    /**
     * @var int
     */
    private const MAX_URL_BYTES = 8388608;

    /**
     * @var array<string, true>
     */
    private const ELEMENTS = [
        'svg' => true,
        'g' => true,
        'rect' => true,
        'circle' => true,
        'ellipse' => true,
        'line' => true,
        'polyline' => true,
        'polygon' => true,
        'path' => true,
        'text' => true,
        'tspan' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const ATTRIBUTES = [
        'width' => true,
        'height' => true,
        'viewBox' => true,
        'x' => true,
        'y' => true,
        'x1' => true,
        'y1' => true,
        'x2' => true,
        'y2' => true,
        'cx' => true,
        'cy' => true,
        'r' => true,
        'rx' => true,
        'ry' => true,
        'd' => true,
        'points' => true,
        'fill' => true,
        'stroke' => true,
        'stroke-width' => true,
        'opacity' => true,
        'transform' => true,
    ];

    public static function definition(string $language): ExtensionDefinition
    {
        return new ExtensionDefinition(
            'carve.imgFence.' . $language,
            '1',
            matcher: ['form' => 'fence', 'name' => $language],
            transform: self::transform(...),
        );
    }

    public static function transform(Claim $claim): ?Node
    {
        $source = $claim->node->f['value'] ?? null;
        if (!is_string($source)) {
            return null;
        }
        $node = new Node('block_extension', []);
        $node->f['name'] = 'carve.imgFence.' . $claim->name;
        $node->f['version'] = '1';
        $node->f['props'] = ['language' => $claim->name];
        $node->f['payload'] = [['format' => 'image/svg+xml', 'value' => $source]];
        $node->f['fallback'] = [FencedRender::bareFallback($claim->node)];

        return $node;
    }

    public static function render(Node $node, RenderHost $host): string
    {
        $items = $node->f['payload'] ?? [];
        $first = is_array($items) ? ($items[0] ?? null) : null;
        $payload = is_array($first) ? ($first['value'] ?? null) : null;
        $svg = is_string($payload) ? self::sanitize($payload) : null;
        if ($svg === null) {
            throw new RuntimeException('the SVG is not a single well-formed safe image');
        }

        return '<img src="data:image/svg+xml,' . rawurlencode($svg) . '" alt="">';
    }

    /**
     * Return only a small SVG tree made of inert, explicitly allowed elements and attributes.
     */
    private static function sanitize(string $source): ?string
    {
        if (strlen($source) > self::MAX_URL_BYTES || str_contains(strtoupper($source), '<!DOCTYPE')) {
            return null;
        }
        if (!class_exists(DOMDocument::class)) {
            return null;
        }
        $prior = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            if (!$document->loadXML($source, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return null;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prior);
        }
        $root = $document->documentElement;
        if ($root === null || $root->localName !== 'svg' || !in_array($root->namespaceURI, [null, '', self::SVG_NS], true)) {
            return null;
        }
        self::clean($root);
        $root->setAttribute('xmlns', self::SVG_NS);
        $svg = $document->saveXML($root);

        return is_string($svg) && strlen(rawurlencode($svg)) <= self::MAX_URL_BYTES ? $svg : null;
    }

    private static function clean(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            if ($attribute->name === 'xmlns' && $element->localName === 'svg') {
                continue;
            }
            if (
                !isset(self::ATTRIBUTES[$attribute->name]) || $attribute->namespaceURI !== null
                || preg_match('/(?:url\s*\(|(?:https?|data|javascript):)/i', $attribute->value) === 1
            ) {
                $element->removeAttributeNode($attribute);
            }
        }
        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                if (!isset(self::ELEMENTS[$child->localName]) || !in_array($child->namespaceURI, [null, '', self::SVG_NS], true)) {
                    $element->removeChild($child);

                    continue;
                }
                self::clean($child);

                continue;
            }
            if ($child->nodeType !== XML_TEXT_NODE || trim($child->textContent ?? '') === '') {
                $element->removeChild($child);
            }
        }
    }
}
