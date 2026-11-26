<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

/**
 * Internal: one note of the adapter note pass (C2-M-104), its references and their fragments.
 */
final class AdapterNote
{
    /**
     * @var array<\MarkupCarve\Carve\Import\HtmlNode>
     */
    public array $refs = [];

    /**
     * @var array<string, true>
     */
    public array $fragments = [];

    public function __construct(public HtmlNode $block)
    {
    }
}
