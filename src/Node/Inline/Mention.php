<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

/**
 * Social reference (Carve: @mention or #tag).
 *
 * A specialised Link: it carries its label as a child Text node and a
 * destination, so profile link policies, link-aware extensions, and the
 * Markdown/plain-text/ANSI renderers (which treat any Link uniformly)
 * all work. The HTML renderer renders it as
 * <a class="<cssClass>" href="<href>"><label></a> with the class
 * attribute first, matching the carve-js reference.
 */
class Mention extends Link
{
    protected string $cssClass;

    public function __construct(string $cssClass, string $href, string $label)
    {
        parent::__construct($href);
        $this->cssClass = $cssClass;
        $this->appendChild(new Text($label));
    }

    public function getCssClass(): string
    {
        return $this->cssClass;
    }

    public function getType(): string
    {
        return 'mention';
    }
}
