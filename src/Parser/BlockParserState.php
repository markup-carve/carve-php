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
        $this->session = self::copyWithoutReferences($this->session);
        $this->frame = self::copyWithoutReferences($this->frame);
        $this->source = self::copyWithoutReferences($this->source);
    }

    /**
     * Legacy parser properties bind state fields by reference. Recreating each
     * object detaches those bindings; native cloning would share their slots.
     *
     * @template T of \MarkupCarve\Carve\Parser\BlockParseSession|\MarkupCarve\Carve\Parser\BlockParseFrame|\MarkupCarve\Carve\Parser\BlockSourceState
     *
     * @param T $state
     *
     * @return T
     */
    private static function copyWithoutReferences(
        BlockParseSession|BlockParseFrame|BlockSourceState $state,
    ): BlockParseSession|BlockParseFrame|BlockSourceState {
        $class = $state::class;
        $copy = new $class();
        foreach (get_object_vars($state) as $property => $value) {
            $copy->{$property} = $value;
        }

        return $copy;
    }
}
