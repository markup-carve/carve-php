<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Html;

use MarkupCarve\Carve2\Diagnostics\Message;
use MarkupCarve\Carve2\Node;

/**
 * The render-loss entries of one render (C2-H-132, C2-A-173).
 */
final class RenderLog
{
    /**
     * @var array<array{code: string, severity: string, message: string, channel: string, file: string|null,
     *     node: \MarkupCarve\Carve2\Node|null, slots: array<string, string|int>, field: string|null}>
     */
    public array $entries = [];

    public ?Node $currentNode = null;

    /**
     * C2-H-052: raw HTML was escaped in this render.
     */
    public bool $escapedRaw = false;

    /**
     * An error that refused the whole render (C2-H-163 `max_length`), or null.
     */
    public ?string $error = null;

    /**
     * @param string $code
     * @param string $severity
     * @param array<string, string|int> $slots The slots of the catalog message.
     * @param string|null $file
     * @param int $variant
     * @param \MarkupCarve\Carve2\Node|null $node
     * @param string|null $field The field named by a loss on a supplied tree (C2-W-138).
     *
     * @return void
     */
    public function loss(
        string $code,
        string $severity,
        array $slots = [],
        ?string $file = null,
        int $variant = 0,
        ?Node $node = null,
        ?string $field = null,
    ): void {
        $this->entries[] = [
            'code' => $code,
            'severity' => $severity,
            'message' => Message::fill($code, $slots, $variant),
            'channel' => 'render-loss',
            'file' => $file,
            'node' => $node ?? $this->currentNode,
            'slots' => $slots,
            'field' => $field,
        ];
    }
}
