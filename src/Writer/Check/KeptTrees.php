<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

/**
 * Trees of short sources whose count `ParseCache` gave up: they only spare a parse (carve-js `KeptTrees`).
 */
final class KeptTrees
{
    /**
     * @var int
     */
    public const MAX_PARSED_SOURCE = 4096;

    /**
     * @var int
     */
    private const MAX_PARSED_TREES = 8192;

    /**
     * @var int
     */
    private const MAX_PARSED_CHARS = 262_144;

    /**
     * @var array<string, \MarkupCarve\Carve\Writer\Check\Node>
     */
    public array $parsed = [];

    public int $chars = 0;

    public function keep(string $source, Node $parsed): void
    {
        if (count($this->parsed) >= self::MAX_PARSED_TREES || $this->chars + strlen($source) > self::MAX_PARSED_CHARS) {
            $this->parsed = [];
            $this->chars = 0;
        }
        $this->parsed[$source] = $parsed;
        $this->chars += strlen($source);
    }
}
