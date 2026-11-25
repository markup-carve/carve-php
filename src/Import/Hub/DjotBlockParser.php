<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use Djot\Parser\BlockParser;

/**
 * The library block parser with the djot.js autolinks of `DjotInlineParser` and the djot.js list markers of
 * `DjotListParser`.
 */
final class DjotBlockParser extends BlockParser
{
    public function __construct()
    {
        parent::__construct();
        $this->inlineParser = new DjotInlineParser($this);
        $this->listParser = new DjotListParser();
    }
}
