<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

/**
 * What a hub import keeps outside the library HTML: the frontmatter and the raw blocks behind their placeholders.
 */
final class Sink
{
    /**
     * @var string
     */
    public const RAW_PLACEHOLDER = '/^<!--carve-hub-raw-(\d+)-->\n$/';

    public ?string $frontmatterFormat = null;

    public string $frontmatter = '';

    /**
     * @var array<array{format: string, value: string}>
     */
    public array $raw = [];

    public static function escape(string $value): string
    {
        return str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], $value);
    }

    /**
     * C2-M-065: a raw block is a placeholder comment the hub swaps back for the block after the import.
     */
    public function rawBlock(string $format, string $value): string
    {
        $this->raw[] = ['format' => $format, 'value' => $value];

        return '<!--carve-hub-raw-' . (count($this->raw) - 1) . '-->';
    }

    /**
     * C2-H-186: inline raw content is the `data-carve-raw` carrier.
     */
    public static function rawInline(string $format, string $value): string
    {
        return '<span data-carve-raw="' . self::escape($format) . '">' . self::escape($value) . '</span>';
    }
}
