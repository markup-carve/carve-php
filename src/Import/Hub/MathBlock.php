<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use League\CommonMark\Node\Block\AbstractBlock;

/**
 * Display math of `micromark-extension-math`: a `$$` fence.
 */
final class MathBlock extends AbstractBlock
{
    public string $literal = '';
}
