<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * Owns the current document session, recursive frame, and source geometry.
 *
 * @internal
 */
final class BlockParserState
{
    public BlockParseSession $session;

    public BlockParseFrame $frame;

    public BlockSourceState $source;

    public function __construct()
    {
        $this->session = new BlockParseSession();
        $this->frame = new BlockParseFrame();
        $this->source = new BlockSourceState();
    }

    public function __clone(): void
    {
        $session = $this->session;
        $this->session = new BlockParseSession();
        foreach (get_object_vars($session) as $property => $value) {
            $this->session->{$property} = $value;
        }
        $frame = $this->frame;
        $this->frame = new BlockParseFrame();
        foreach (get_object_vars($frame) as $property => $value) {
            $this->frame->{$property} = $value;
        }
        $source = $this->source;
        $this->source = new BlockSourceState();
        foreach (get_object_vars($source) as $property => $value) {
            $this->source->{$property} = $value;
        }
    }
}
