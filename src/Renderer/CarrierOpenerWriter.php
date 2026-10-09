<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use MarkupCarve\Carve\Node\Node;

/**
 * Spells a container's Carve opener and closer for a PART 11 §10s carrier
 * marker.
 *
 * It subclasses the canonical writer rather than composing the opener a second
 * time: the payload is Carve source, and a second spelling of it would drift
 * from the one `carve fmt` writes. The body is suppressed so a nested
 * container costs one fence line, not a second render of its subtree.
 */
final class CarrierOpenerWriter extends CarveRenderer
{
    /**
     * @return array{prelude: list<string>, opener: string, closer: string}|null
     */
    public function spell(Node $node, int $depth): ?array
    {
        $this->colonFenceDepth = $depth;
        $lines = explode("\n", $this->renderBlock($node));
        $open = null;
        $close = null;
        foreach ($lines as $index => $line) {
            if (preg_match('/^:{3,}/', $line) !== 1) {
                continue;
            }
            $open ??= $index;
            if (preg_match('/^:{3,}$/D', $line) === 1) {
                $close = $index;
            }
        }
        if ($open === null || $close === null || $close === $open) {
            return null;
        }

        return [
            'prelude' => array_slice($lines, 0, $open),
            'opener' => $lines[$open],
            'closer' => $lines[$close],
        ];
    }

    protected function renderColonFenceBody(Node $node): string
    {
        return '';
    }
}
