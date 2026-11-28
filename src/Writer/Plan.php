<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

/**
 * One group's planned write, with its escapes and undecided leaves.
 * The fields correspond to carve-js write-plan.ts Plan.
 */
final class Plan
{
    /**
     * @var int
     */
    public const AS_CONTENT = 0;

    /**
     * @var int
     */
    public const AS_INLINES = 1;

    /**
     * @var int
     */
    public const AS_LINE = 2;

    /**
     * @var int
     */
    public const LINE_START = 1;

    /**
     * @var int
     */
    public const AFTER_MARK = 0;

    /**
     * @var int
     */
    public const IN_TITLE = 2;

    public ?string $undecided = null;

    public string $detail = '';

    /**
     * @var array<string, true>
     */
    public array $covered = [];

    /**
     * @var array<string, true>
     */
    public array $opaqueBlocks = [];

    /**
     * @var array<string, array<int, true>>
     */
    public array $escapes = [];

    /**
     * @var array<int, string>
     */
    public array $respell = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $places = [];

    public int $leaves = 0;

    /**
     * @var array<int, string>
     */
    public array $lost = [];

    public int $nameEscapes = 0;

    public bool $namesBesideMarks = false;
}
