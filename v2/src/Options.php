<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2;

/**
 * Validated options of options.json that affect parse, resolve and HTML.
 */
final class Options
{
    /**
     * @var array<string, mixed>
     */
    public array $values = [];

    /**
     * @param array<string, mixed> $options
     *
     * @return self
     */
    public static function from(array $options): self
    {
        $o = new self();
        $o->values = $options;

        return $o;
    }
}
