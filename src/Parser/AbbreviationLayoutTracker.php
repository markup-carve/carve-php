<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Parser\Utility\IndentationHelper;

/**
 * Carries abbreviation-definition scope through the shared definition walk.
 */
final class AbbreviationLayoutTracker
{
    /**
     * @var string
     */
    private const LIST_ITEM_PATTERN = '/^[ \t]*(?:[-*]|\.|(?:[0-9]+|[ivxlcdm]+|[IVXLCDM]+|[a-zA-Z])[.)]) +[ \t]*[^ \t]/';

    /**
     * @var string
     */
    private const DEFINITION_PATTERN = '/^\*\[([A-Za-z0-9]+)\]: +(?![ \t]*$)([^ ].*)$/';

    private ?string $fenceChar = null;

    private int $fenceLength = 0;

    private int $verseFence = 0;

    /**
     * @var list<int>
     */
    private array $divs = [];

    private bool $inListItem = false;

    private PrepassCommentFence $commentFence;

    /**
     * @param array<string> $lines
     */
    public function __construct(array $lines)
    {
        $this->commentFence = new PrepassCommentFence($lines);
    }

    /**
     * @return array{abbr: string, expansion: string}|null
     */
    public function observe(string $line, int $index): ?array
    {
        if (IndentationHelper::isBlankLine($line)) {
            $this->inListItem = false;
        } elseif (preg_match(self::LIST_ITEM_PATTERN, $line) === 1) {
            $this->inListItem = true;
        }

        if ($this->fenceChar !== null) {
            if (
                preg_match('/^([`~]{3,})[ \t]*$/', $line, $match) === 1
                && $match[1][0] === $this->fenceChar
                && strlen($match[1]) >= $this->fenceLength
            ) {
                $this->fenceChar = null;
                $this->fenceLength = 0;
            }

            return null;
        }
        if ($this->verseFence > 0) {
            if (preg_match('/^(:{3,})[ \t]*$/', $line, $match) === 1 && strlen($match[1]) >= $this->verseFence) {
                $this->verseFence = 0;
            }

            return null;
        }
        if ($this->commentFence->isOpen()) {
            $this->commentFence->advance($line);

            return null;
        }
        if ($this->commentFence->opensOn($line, $index, 0)) {
            return null;
        }
        if (preg_match('/^([`~]{3,})/', $line, $match) === 1) {
            $this->fenceChar = $match[1][0];
            $this->fenceLength = strlen($match[1]);

            return null;
        }
        if (preg_match('/^(:{3,})[ \t]*\|(?:[ \t]*\{.*\})?[ \t]*$/', $line, $match) === 1) {
            $this->verseFence = strlen($match[1]);

            return null;
        }
        if (preg_match('/^(:{3,})[ \t]*(.*)$/', $line, $match) === 1) {
            $width = strlen($match[1]);
            if ($match[2] === '' && $this->divs !== [] && end($this->divs) === $width) {
                array_pop($this->divs);
            } else {
                $this->divs[] = $width;
            }
        }

        if (
            ($line[0] ?? '') !== '*'
            || $this->divs !== []
            || $this->inListItem
            || preg_match(self::DEFINITION_PATTERN, $line, $match) !== 1
        ) {
            return null;
        }

        return ['abbr' => $match[1], 'expansion' => rtrim($match[2], " \t")];
    }
}
