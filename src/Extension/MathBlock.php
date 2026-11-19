<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;

/**
 * Provisional. A `math` fence as `<div class="math display">\[…\]</div>`, the delimiters KaTeX and MathJax read.
 */
final class MathBlock
{
    /**
     * @param string $language Fence language that marks a display-math block.
     * @param (\Closure(string, bool): string)|null $build Server-side math for static mode (C2-X-052).
     */
    public static function bundle(string $language = 'math', ?Closure $build = null): Bundle
    {
        $open = static fn (Node $node, RenderHost $host): string => '<div'
            . $host->attributes(NodeAttributes::with($node, ['math', 'display'])) . '>';
        $render = static fn (Node $node, RenderHost $host): string => $open($node, $host)
            . '\\[' . RenderHost::escapeText(FencedRender::source($node)) . '\\]</div>';
        $static = $build === null ? null : static fn (Node $node, RenderHost $host): string => $open($node, $host)
            . $build(FencedRender::source($node), true) . '</div>';

        return new Bundle([
            new ExtensionDefinition(
                'carve.mathBlock',
                '1',
                matcher: ['form' => 'fence', 'name' => $language],
                transform: self::transform(...),
                renderer: $render,
                buildTimeRenderer: $static,
            ),
        ]);
    }

    public static function transform(Claim $claim): ?Node
    {
        if ($claim->form !== 'fence') {
            return null;
        }
        $node = new Node('block_extension', []);
        $node->f['name'] = 'carve.mathBlock';
        $node->f['version'] = '1';
        $node->f['payload'] = [['format' => 'application/x-tex', 'value' => PlainText::str($claim->node->f['value'] ?? '')]];
        $node->f['fallback'] = [FencedRender::bareFallback($claim->node)];

        return $node;
    }
}
