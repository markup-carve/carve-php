<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Block;

/**
 * Receives the blocks of the block pass in document order.
 */
interface Sink
{
    /**
     * A container opened.
     *
     * @param \MarkupCarve\Carve\Block\Frame $frame
     * @param \MarkupCarve\Carve\Block\Frame $parent
     *
     * @return void
     */
    public function enter(Frame $frame, Frame $parent): void;

    /**
     * A leaf closed with all its lines.
     *
     * @param \MarkupCarve\Carve\Block\Frame $frame
     * @param \MarkupCarve\Carve\Block\Frame $parent
     *
     * @return void
     */
    public function leaf(Frame $frame, Frame $parent): void;

    /**
     * A container closed; its close facts are set on the frame.
     *
     * @param \MarkupCarve\Carve\Block\Frame $frame
     *
     * @return void
     */
    public function exit(Frame $frame): void;

    /**
     * A caption closed (C2-B-146, C2-B-147): it attaches to `$target`, the block of `$parent` that
     * closed directly above it, together with the comments that followed that block (C2-A-082).
     *
     * @param \MarkupCarve\Carve\Block\Frame $caption
     * @param \MarkupCarve\Carve\Block\Frame $parent
     * @param \MarkupCarve\Carve\Block\Frame $target
     *
     * @return void
     */
    public function caption(Frame $caption, Frame $parent, Frame $target): void;
}
