<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use League\CommonMark\Environment\EnvironmentInterface;
use League\CommonMark\Parser\Block\BlockStart;
use League\CommonMark\Parser\Block\BlockStartParserInterface;
use League\CommonMark\Parser\Block\ParagraphParser;
use League\CommonMark\Parser\Cursor;
use League\CommonMark\Parser\MarkdownParserStateInterface;
use MarkupCarve\Carve\Import\ImportInputError;

/**
 * Refuses an inline block with more candidate positions than MAX_CANDIDATES. league/commonmark keeps an entry for every
 * position an inline parser regex matches in a block (1.3 to 2.7 KB each), so one long paragraph of punctuation would
 * exhaust the memory limit. Never starts a block: league asks it about every line that can hold inline content but a
 * table row (see TableBudgetStartParser), and never about lines inside code or HTML blocks.
 */
final class InlineBudgetParser implements BlockStartParserInterface
{
    /**
     * Inline candidate positions one paragraph, heading or table row may hold: at most about 40 MB in league/commonmark,
     * and room under a 128 MB limit for the nodes the block becomes.
     *
     * @var int
     */
    public const MAX_CANDIDATES = 16384;

    /**
     * @var array<array{string, bool}>|null
     */
    private ?array $regexes = null;

    private int $regexTotal = 0;

    private ?ParagraphParser $paragraph = null;

    private int $total = 0;

    private bool $exact = false;

    private int $lastCount = 0;

    private ?string $lastLine = null;

    private int $lastPosition = 0;

    /**
     * @param \League\CommonMark\Environment\EnvironmentInterface $environment
     * @param int $maxNesting The environment's `max_nesting_level`.
     */
    public function __construct(private EnvironmentInterface $environment, private int $maxNesting)
    {
    }

    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        // At max_nesting_level league asks no block start about a line, so this parser would miss it. A list start adds
        // two levels at once; a chain that deep is past the tree depth anyway (C2-A-171).
        if ($parserState->getLastMatchedBlockParser()->getBlock()->getDepth() >= $this->maxNesting - 2) {
            throw new ImportInputError('The input exceeds the nesting limits of a tree (C2-A-171)');
        }
        // A container start on this line asks again further along it; the first count already covered the rest.
        $line = $cursor->getLine();
        if ($cursor->getPosition() > $this->lastPosition && $line === $this->lastLine) {
            $this->lastPosition = $cursor->getPosition();

            return BlockStart::none();
        }
        $this->lastLine = $line;
        $this->lastPosition = $cursor->getPosition();

        // Each regex matches at most once per byte, so a block is counted exactly only once that bound can pass.
        $count = $this->exact ? $this->candidates($cursor->getRemainder()) : strlen($line) * $this->regexCount();
        $active = $parserState->getActiveBlockParser();
        if (!$active instanceof ParagraphParser) {
            $this->paragraph = null;
            $this->total = $count;
            $this->exact = false;
        } elseif ($active === $this->paragraph) {
            // The line break before this line is a candidate too.
            $this->total += $count + 1;
        } else {
            // The paragraph opened on the previous line.
            $this->paragraph = $active;
            $this->total = $this->lastCount + $count + 1;
            $this->exact = false;
        }
        $this->lastCount = $count;
        if ($this->total > self::MAX_CANDIDATES && !$this->exact) {
            $this->exact = true;
            $text = $cursor->getRemainder();
            $this->lastCount = $this->candidates($text);
            $this->total = $active instanceof ParagraphParser
                ? $this->candidates($active->getContentString() . "\n" . $text)
                : $this->lastCount;
        }
        if ($this->total > self::MAX_CANDIDATES) {
            self::refuse();
        }

        return BlockStart::none();
    }

    /**
     * A line read on its own, as a table row is: league shows no block start parser a row of a table.
     */
    public function checkLine(string $line): void
    {
        if (strlen($line) * $this->regexCount() > self::MAX_CANDIDATES && $this->candidates($line) > self::MAX_CANDIDATES) {
            self::refuse();
        }
    }

    private static function refuse(): never
    {
        throw new ImportInputError(sprintf(
            'an inline block holds more than %d inline candidate positions (the Markdown import limit)',
            self::MAX_CANDIDATES,
        ));
    }

    private function regexCount(): int
    {
        if ($this->regexes === null) {
            $this->regexes = [];
            foreach ($this->environment->getInlineParsers() as $parser) {
                $regex = $parser->getMatchDefinition()->getRegex();
                $this->regexes[] = [$regex, strlen($regex) !== mb_strlen($regex, 'UTF-8')];
            }
            $this->regexTotal = count($this->regexes);
        }

        return $this->regexTotal;
    }

    private function candidates(string $text): int
    {
        $this->regexCount();
        $multibyte = !mb_check_encoding($text, 'ASCII');
        $count = 0;
        foreach ($this->regexes ?? [] as [$regex, $regexMultibyte]) {
            $count += (int)preg_match_all($multibyte || $regexMultibyte ? $regex . 'u' : $regex, $text);
        }

        return $count;
    }
}
