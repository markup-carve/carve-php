<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Markdown;

/**
 * Raw HTML block state for one Markdown conversion.
 *
 * @internal
 */
final class RawHtmlBlockState
{
    /**
     * Terminator pattern of an open condition 1-5 block.
     */
    public ?string $closer = null;

    /**
     * A condition 1-5 block ended on the previous line.
     */
    public bool $breakOwed = false;

    /**
     * A condition 6 or 7 block remains open until a blank line.
     */
    public bool $blockOpen = false;

    /**
     * The previous line carried content inside its container.
     */
    public bool $prevHadContent = false;

    /**
     * Container in which the tracked block opened.
     */
    public ?string $container = null;

    public function inBlock(): bool
    {
        return $this->closer !== null || $this->blockOpen;
    }

    public function endAtBlank(): void
    {
        $this->breakOwed = false;
        $this->blockOpen = false;
    }

    public function enterContainer(string $key): void
    {
        if ($key !== $this->container) {
            $this->closer = null;
            $this->blockOpen = false;
        }
        $this->container = $key;
    }
}
