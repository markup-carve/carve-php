<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use MarkupCarve\Carve\Node\Document;

/**
 * Interface for renderers that convert a Document AST to string output
 */
interface RendererInterface
{
    /**
     * Absolute recursion ceiling for every public Document-accepting render path.
     *
     * ONE DEFINITION, because there were five. Each renderer carried its own
     * `= 512`, which is the drift PART 9 §25 is about and which had already
     * happened: the Carve writer sat at 232 while the other four were at 512, so
     * a hand-built tree of 300 nested quotes rendered to HTML and could not be
     * formatted by the same engine (carve-php#835).
     *
     * THE DERIVATION, which §25 requires be stated rather than borrowed. This
     * engine counts CONTAINER DEPTH, the same unit as
     * `BlockParser::MAX_NESTING_DEPTH` (200). The worst per-level cost in that
     * unit is 2: a container level contributes the container itself and then the
     * block inside it before the next level begins. So the floor is
     * 2 x MAX_NESTING_DEPTH = 400, and no tree `parse` produces can reach it.
     * 512 is that floor with headroom. `RenderCeilingsAgreeTest` checks the
     * relationship rather than the number, so the derivation is verified and not
     * merely asserted here.
     *
     * NOT a cross-engine constant. carve-js uses 232 and carve-rs 632, each
     * derived in its own unit; §25 forbids adopting another implementation's
     * number without redoing the derivation, and markup-carve/carve#754 is where
     * that was settled.
     *
     * @var int
     */
    public const MAX_RENDER_DEPTH = 512;

    public function render(Document $document): string;
}
