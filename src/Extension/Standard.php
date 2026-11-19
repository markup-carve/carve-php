<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;

/**
 * The named standard matchers in the 0.2 extension catalog.
 */
final class Standard
{
    /**
     * @return array<\MarkupCarve\Carve\Extension\ExtensionDefinition>|null
     */
    public static function definitions(string $name): ?array
    {
        return match ($name) {
            'carve.tabs' => Tabs::extensions(),
            'carve.wiki' => [
                new ExtensionDefinition(
                    'carve.wiki',
                    '1', matcher: ['form' => 'inline', 'name' => 'wiki'], transform: self::inline(...),
                ),
            ],
            'carve.cite' => [Citations::citeDefinition()],
            'carve.imgFence.img' => [ImgFence::definition('img')],
            'carve.imgFence.image' => [ImgFence::definition('image')],
            default => null,
        };
    }

    public static function renderer(string $name, string $mode): ?Closure
    {
        return match ($name) {
            'carve.tabs' => $mode === 'static' ? Tabs::renderStatic(...) : Tabs::render(...),
            'carve.imgFence.img', 'carve.imgFence.image' => ImgFence::render(...),
            default => null,
        };
    }

    /**
     * C2-X-057: the plain text of the parsed content is the inline fallback.
     */
    public static function inline(Claim $claim): Node
    {
        $node = new Node('inline_extension', []);
        $node->f['name'] = 'carve.' . $claim->name;
        $node->f['version'] = '1';
        $text = new Node('text');
        $text->f['value'] = PlainText::of($claim->node->children ?? []);
        $node->f['fallback'] = [$text];

        return $node;
    }
}
