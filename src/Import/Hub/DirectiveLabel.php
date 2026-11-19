<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use League\CommonMark\Node\Block\AbstractBlock;

/**
 * The `[label]` of a container directive, parsed as inline content.
 */
final class DirectiveLabel extends AbstractBlock
{
}
