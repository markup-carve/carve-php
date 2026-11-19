<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Extension;

use Closure;
use MarkupCarve\Carve2\Node;

/**
 * The named standard matchers in the 0.2 extension catalog.
 */
final class Standard
{
    /**
     * @return array<\MarkupCarve\Carve2\Extension\ExtensionDefinition>|null
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
            'carve.cite' => [
                new ExtensionDefinition(
                    'carve.cite',
                    '1', matcher: ['form' => 'inline', 'name' => 'cite'], transform: self::inline(...),
                ),
            ],
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
     * C2-X-057 and C2-X-058: keep the parsed content as the inline fallback.
     */
    public static function inline(Claim $claim): Node
    {
        $node = new Node('inline_extension', []);
        $node->f['name'] = 'carve.' . $claim->name;
        $node->f['version'] = '1';
        $node->f['fallback'] = array_map(Extensions::copy(...), $claim->node->children ?? []);

        return $node;
    }
}
