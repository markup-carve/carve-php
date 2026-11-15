<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Block;

/**
 * Receives the blocks of the block pass in document order.
 */
interface Sink
{
    /**
     * A container opened.
     *
     * @param \MarkupCarve\Carve2\Block\Frame $frame
     * @param \MarkupCarve\Carve2\Block\Frame $parent
     *
     * @return void
     */
    public function enter(Frame $frame, Frame $parent): void;

    /**
     * A leaf closed with all its lines.
     *
     * @param \MarkupCarve\Carve2\Block\Frame $frame
     * @param \MarkupCarve\Carve2\Block\Frame $parent
     *
     * @return void
     */
    public function leaf(Frame $frame, Frame $parent): void;

    /**
     * A container closed; its close facts are set on the frame.
     *
     * @param \MarkupCarve\Carve2\Block\Frame $frame
     *
     * @return void
     */
    public function exit(Frame $frame): void;
}
