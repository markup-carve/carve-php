<?php

declare(strict_types=1);

namespace Carve\Parser;

use Carve\Exception\ParseException;
use Carve\Exception\ParseWarning;
use Carve\Node\Block\BlockQuote;
use Carve\Node\Block\Caption;
use Carve\Node\Block\CodeBlock;
use Carve\Node\Block\Comment;
use Carve\Node\Block\DefinitionDescription;
use Carve\Node\Block\DefinitionList;
use Carve\Node\Block\DefinitionTerm;
use Carve\Node\Block\Div;
use Carve\Node\Block\Figure;
use Carve\Node\Block\Footnote;
use Carve\Node\Block\Heading;
use Carve\Node\Block\LineBlock;
use Carve\Node\Block\ListBlock;
use Carve\Node\Block\ListItem;
use Carve\Node\Block\Paragraph;
use Carve\Node\Block\RawBlock;
use Carve\Node\Block\Table;
use Carve\Node\Block\TableCell;
use Carve\Node\Block\TableRow;
use Carve\Node\Block\ThematicBreak;
use Carve\Node\Document;
use Carve\Node\Inline\HardBreak;
use Carve\Node\Inline\Image;
use Carve\Node\Inline\Math;
use Carve\Node\Inline\SoftBreak;
use Carve\Node\Inline\Text;
use Carve\Node\Node;
use Carve\Parser\Block\FencedBlockParser;
use Carve\Parser\Block\ListParser;
use Carve\Parser\Block\TableParser;
use Carve\Parser\Utility\AttributeParser;
use Carve\Parser\Utility\IndentationHelper;
use Carve\Renderer\HeadingIdTracker;
use Closure;

/**
 * Block-level parser for Djot
 */
class BlockParser
{
    /**
     * Neutral starting point for incremental brace scanning.
     *
     * @var array{depth: int, inQuote: bool, quoteChar: string, pendingEscape: bool}
     */
    private const INITIAL_BRACE_STATE = ['depth' => 0, 'inQuote' => false, 'quoteChar' => '', 'pendingEscape' => false];

    /**
     * Initial trailing-block tracker state for list-item lazy continuation.
     *
     * `openParagraph` starts true: an empty item (no block yet) can absorb a
     * lazy line. See advanceTrailingBlockState().
     *
     * @var array{openParagraph: bool, inFence: bool, fenceChar: string, fenceLength: int, inDiv: bool, divFenceLength: int}
     */
    private const INITIAL_TRAILING_BLOCK_STATE = ['openParagraph' => true, 'inFence' => false, 'fenceChar' => '', 'fenceLength' => 0, 'inDiv' => false, 'divFenceLength' => 0];

    /**
     * Abbreviation definitions use a space-free alphanumeric term and require
     * one space after the colon.
     *
     * @var string
     */
    private const ABBREVIATION_DEFINITION_PATTERN = '/^\*\[([A-Za-z0-9]+)\]: (.*)$/';

    /**
     * Maximum block-container nesting depth. Every level of blockquote / div /
     * list / footnote recurses through parseBlocks(), so unbounded nesting (e.g.
     * `> ` repeated thousands of times) exhausts the stack or memory. Past this
     * depth, container content is emitted as a literal paragraph instead of
     * recursing. Far above any real document; only adversarial input reaches it.
     *
     * @var int
     */
    private const MAX_NESTING_DEPTH = 200;

    private int $nestingDepth = 0;

    protected InlineParser $inlineParser;

    protected ListParser $listParser;

    protected TableParser $tableParser;

    protected FencedBlockParser $fencedBlockParser;

    /**
     * @var array<string, \Carve\Parser\ReferenceDefinition>
     */
    protected array $references = [];

    /**
     * Heading-derived references keyed by folded heading text. Used only for
     * unresolved collapsed references (`[text][]`), after exact definitions lose.
     *
     * @var array<string, \Carve\Parser\ReferenceDefinition>
     */
    protected array $headingReferencesByFoldedLabel = [];

    /**
     * @var array<string, \Carve\Node\Block\Footnote>
     */
    protected array $footnotes = [];

    /**
     * Abbreviation definitions: maps abbreviation text to its definition
     *
     * @var array<string, string>
     */
    protected array $abbreviations = [];

    /**
     * Pending block attributes to apply to next block
     *
     * @var array<string, string>
     */
    protected array $pendingAttributes = [];

    /**
     * Whether to collect warnings during parsing
     */
    protected bool $collectWarnings = false;

    /**
     * Whether to throw on parse errors
     */
    protected bool $strictMode = false;

    /**
     * Collected warnings during parsing
     *
     * @var array<\Carve\Exception\ParseWarning>
     */
    protected array $warnings = [];

    /**
     * References that have been used (for validation)
     * Only populated when collectWarnings is true
     *
     * @var array<string, int> Maps reference label to line where used
     */
    protected array $usedReferences = [];

    /**
     * Anchor links found during parsing (for validation)
     * Only populated when collectWarnings is true
     *
     * @var array<array{fragment: string, line: int, column: int}>
     */
    protected array $anchorLinks = [];

    /**
     * Heading IDs generated during heading reference extraction
     * Used for anchor link validation
     *
     * @var array<string, true>
     */
    protected array $headingIds = [];

    /**
     * Current line offset for nested parsing (0-indexed internally, 1-indexed for errors)
     */
    protected int $lineOffset = 0;

    /**
     * Custom block patterns: array of [pattern => callback]
     * Callback receives (array $lines, int $startIndex, Node $parent, BlockParser $parser)
     * and should return number of lines consumed, or null if not matched
     *
     * @var array<string, callable(array<string>, int, \Carve\Node\Node, self): ?int>
     */
    protected array $customBlockPatterns = [];

    /**
     * @var array<array{matcher: \Closure, priority: int, seq: int, pattern: string|null}>
     */
    protected array $blockMatchers = [];

    protected int $blockMatcherSeq = 0;

    /**
     * @var array<\Closure>|null
     */
    protected ?array $sortedBlockMatchers = null;

    protected ?Node $currentMatcherParent = null;

    /**
     * Optional slug transform mirrored onto the parse-time heading-id
     * tracker so implicit `[Heading][]` references agree with the
     * render-time ids (set by AsciiHeadingIdsExtension).
     */
    protected ?Closure $headingIdTransformer = null;

    /**
     * Mirrors the render-time tracker's opt-in lowercase flag so implicit
     * `[Heading][]` references agree with the (lowercased) emitted ids.
     */
    protected bool $headingIdLowercase = false;

    public function setHeadingIdTransformer(?Closure $headingIdTransformer): void
    {
        $this->headingIdTransformer = $headingIdTransformer;
    }

    public function setHeadingIdLowercase(bool $lowercase): void
    {
        $this->headingIdLowercase = $lowercase;
    }

    public function __construct(
        bool $collectWarnings = false,
        bool $strictMode = false,
    ) {
        $this->collectWarnings = $collectWarnings;
        $this->strictMode = $strictMode;
        $this->inlineParser = new InlineParser($this);
        $this->listParser = new ListParser();
        $this->tableParser = new TableParser();
        $this->fencedBlockParser = new FencedBlockParser();
    }

    /**
     * Register a custom block pattern
     *
     * The pattern should match the first line of the block.
     * The callback receives the full lines array, start index, parent node, and parser,
     * and should return the number of lines consumed (or null if not a match).
     *
     * Example - :::spoiler blocks:
     * ```php
     * $parser->addBlockPattern('/^:::spoiler\s*$/', function($lines, $start, $parent, $parser) {
     *     $endPattern = '/^:::\s*$/';
     *     $content = [];
     *     $i = $start + 1;
     *     while ($i < count($lines) && !preg_match($endPattern, $lines[$i])) {
     *         $content[] = $lines[$i];
     *         $i++;
     *     }
     *     $div = new Div();
     *     $div->setAttribute('class', 'spoiler');
     *     // Parse content inside
     *     $parser->parseBlockContent($div, $content);
     *     $parent->appendChild($div);
     *     return $i - $start + 1; // +1 for closing :::
     * });
     * ```
     *
     * Example - custom admonitions:
     * ```php
     * $parser->addBlockPattern('/^!!!\s*(note|warning|danger)\s*$/', function($lines, $start, $parent, $parser) {
     *     $type = trim(substr($lines[$start], 3));
     *     $content = [];
     *     $i = $start + 1;
     *     while ($i < count($lines) && preg_match('/^\s+/', $lines[$i])) {
     *         $content[] = ltrim($lines[$i]);
     *         $i++;
     *     }
     *     $div = new Div();
     *     $div->setAttribute('class', 'admonition ' . $type);
     *     $parser->parseBlockContent($div, $content);
     *     $parent->appendChild($div);
     *     return $i - $start;
     * });
     * ```
     *
     * @param string $pattern Regex pattern to match the first line
     * @param callable(array<string>, int, \Carve\Node\Node, self): ?int $callback
     */
    public function addBlockPattern(string $pattern, callable $callback): void
    {
        $this->removeBlockPattern($pattern);
        $this->customBlockPatterns[$pattern] = $callback;
        $self = $this;

        $this->registerBlockMatcher(
            function (array $lines, int $start, MatcherContext $ctx) use ($pattern, $callback, $self): ?int {
                if (!preg_match($pattern, $lines[$start])) {
                    return null;
                }

                // Legacy callbacks append their node(s) to the parent themselves
                // and return the number of lines consumed. Preserve that contract
                // verbatim — a pattern emitting several sibling blocks keeps them
                // flat, with no synthetic wrapper. The dispatcher reads the int
                // return as "already appended".
                $parent = $self->currentMatcherParent;
                if ($parent === null) {
                    return null;
                }

                $consumed = $callback($lines, $start, $parent, $self);

                return is_int($consumed) ? $consumed : null;
            },
            pattern: $pattern,
        );
    }

    /**
     * Remove a custom block pattern
     */
    public function removeBlockPattern(string $pattern): void
    {
        unset($this->customBlockPatterns[$pattern]);
        $this->blockMatchers = array_values(array_filter(
            $this->blockMatchers,
            static fn (array $entry): bool => $entry['pattern'] !== $pattern,
        ));
        $this->sortedBlockMatchers = null;
    }

    /**
     * Get all registered custom block patterns
     *
     * @return array<string, callable>
     */
    public function getBlockPatterns(): array
    {
        return $this->customBlockPatterns;
    }

    /**
     * @param \Closure(array<string>, int, \Carve\Parser\MatcherContext): (array{node: \Carve\Node\Node, linesConsumed: int}|null) $matcher
     * @param int $priority
     */
    public function addBlockMatcher(Closure $matcher, int $priority = 0): void
    {
        $this->registerBlockMatcher($matcher, $priority);
    }

    /**
     * @param \Closure(array<string>, int, \Carve\Parser\MatcherContext): (int|array{node: \Carve\Node\Node, linesConsumed: int}|null) $matcher
     * @param int $priority
     * @param string|null $pattern
     */
    protected function registerBlockMatcher(Closure $matcher, int $priority = 0, ?string $pattern = null): void
    {
        $this->blockMatchers[] = [
            'matcher' => $matcher,
            'priority' => $priority,
            'seq' => $this->blockMatcherSeq++,
            'pattern' => $pattern,
        ];
        $this->sortedBlockMatchers = null;
    }

    /**
     * @return array<\Closure>
     */
    protected function sortedBlockMatchers(): array
    {
        if ($this->sortedBlockMatchers !== null) {
            return $this->sortedBlockMatchers;
        }

        $entries = $this->blockMatchers;
        usort($entries, static function (array $a, array $b): int {
            return $b['priority'] <=> $a['priority'] ?: $a['seq'] <=> $b['seq'];
        });

        return $this->sortedBlockMatchers = array_map(
            static fn (array $entry): Closure => $entry['matcher'],
            $entries,
        );
    }

    /**
     * Parse block content (for use in custom block callbacks)
     *
     * @param \Carve\Node\Node $parent
     * @param array<string> $lines
     */
    public function parseBlockContent(Node $parent, array $lines): void
    {
        $this->parseBlocks($parent, $lines, 0);
    }

    /**
     * Enable or disable warning collection
     */
    public function setCollectWarnings(bool $collect): self
    {
        $this->collectWarnings = $collect;

        return $this;
    }

    /**
     * Enable or disable strict mode
     */
    public function setStrictMode(bool $strict): self
    {
        $this->strictMode = $strict;

        return $this;
    }

    /**
     * Get collected warnings
     *
     * @return array<\Carve\Exception\ParseWarning>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Clear collected warnings
     */
    public function clearWarnings(): self
    {
        $this->warnings = [];

        return $this;
    }

    /**
     * Add a warning or throw exception in strict mode
     *
     * @throws \Carve\Exception\ParseException In strict mode for errors
     */
    protected function addWarning(
        string $message,
        int $line,
        int $column = 1,
        bool $isError = false,
        ?string $category = null,
        ?string $suggestion = null,
    ): void {
        // Convert from 0-indexed to 1-indexed for user-facing messages
        $line = $line + $this->lineOffset + 1;

        if ($isError && $this->strictMode) {
            throw new ParseException($message, $line, $column);
        }

        if ($this->collectWarnings) {
            $this->warnings[] = new ParseWarning($message, $line, $column, $category, $suggestion);
        }
    }

    public function parse(string $input): Document
    {
        // Capture the original source byte length before any normalization so
        // renderers can size the abbreviation-expansion budget (DoS guard).
        $sourceLength = strlen($input);
        $this->references = [];
        $this->headingReferencesByFoldedLabel = [];
        $this->footnotes = [];
        $this->abbreviations = [];
        $this->pendingAttributes = [];
        $this->warnings = [];
        $this->usedReferences = [];
        $this->anchorLinks = [];
        $this->headingIds = [];
        $this->lineOffset = 0;
        $document = new Document();
        // Strip a single leading UTF-8 BOM (U+FEFF) at the document start so
        // `﻿# T` is a heading, not literal text. Root only: this is the
        // top-level entry; nested content is parsed from line arrays.
        if (str_starts_with($input, "\u{FEFF}")) {
            $input = substr($input, 3);
        }
        // Replace any NUL (U+0000) with the U+FFFD replacement character so a
        // control byte never reaches output (decided cross-impl behavior;
        // WHATWG-style). For carve-php this also prevents an input NUL from
        // colliding with the internal SOFT_BREAK_GUARD sentinel (also \x00).
        if (str_contains($input, "\0")) {
            $input = str_replace("\0", "\u{FFFD}", $input);
        }
        $lines = $this->splitLines($input);

        // First pass: extract reference definitions, footnotes, abbreviations, and heading references
        $this->extractReferences($lines);
        $this->extractFootnotes($lines);
        $this->extractAbbreviations($lines);
        $this->extractHeadingReferences($lines);

        // Second pass: parse blocks
        $this->parseBlocks($document, $lines, 0, topLevel: true);

        // Append footnotes section if any
        foreach ($this->footnotes as $footnote) {
            $document->appendChild($footnote);
        }

        // Validate references and anchor links if warnings are enabled
        if ($this->collectWarnings) {
            $this->validateReferences();
            $this->validateAnchorLinks($document);
        }

        // Store abbreviations on document for round-trip support
        if ($this->abbreviations !== []) {
            $document->setAbbreviations($this->abbreviations);
        }

        // Record the source byte length so renderers can size the
        // abbreviation-expansion budget (output-amplification DoS guard).
        $document->setSourceLength($sourceLength);

        return $document;
    }

    /**
     * Extract reference link definitions from the document
     *
     * @param array<string> $lines
     */
    protected function extractReferences(array $lines): void
    {
        $i = 0;
        $count = count($lines);
        $pendingAttrs = [];
        $pendingAttrsInQuote = false;
        $pendingAttrsInList = false;
        // Track open fenced code block so `[r]: /u` (or `> [r]: /u`)
        // shown inside a code sample is not collected as a real def.
        $fenceChar = null;
        $fenceLen = 0;

        while ($i < $count) {
            $line = $lines[$i];

            // Fenced-code opacity: definitions inside ``` / ~~~ blocks
            // are literal samples, never registered.
            if ($fenceChar !== null) {
                if (
                    preg_match('/^[ ]{0,3}([`~]{3,})\s*$/', $line, $fm)
                    && $fm[1][0] === $fenceChar
                    && strlen($fm[1]) >= $fenceLen
                ) {
                    $fenceChar = null;
                    $fenceLen = 0;
                }
                $i++;

                continue;
            }
            if (preg_match('/^[ ]{0,3}([`~]{3,})/', $line, $fm)) {
                $fenceChar = $fm[1][0];
                $fenceLen = strlen($fm[1]);
                $i++;

                continue;
            }

            // Reference definitions are allowed inside blockquotes and list
            // items (those containers consume the definition line without
            // rendering it, but it must still populate the global ref map).
            // Strip leading container markers before the def regex tests.
            // The `>` must sit at column 0 (no preceding whitespace) so
            // an indented `    > [r]: /u` line, which is paragraph or
            // code continuation, is not misclassified as a definition.
            // The space after each `>` is OPTIONAL (blockQuoteLineContent), so
            // a reference definition inside a tight (`>[r]: /u`) or nested
            // (`>>[r]: /u`) blockquote must be stripped the same way the quote
            // parser strips it -- `>` then an optional LITERAL space (not a
            // tab), exactly mirroring blockQuoteLineContent -- else the prepass
            // and the real parse disagree on `>\t[r]: /u`.
            $bare = $line;
            $inQuote = false;
            $inList = false;
            do {
                $previousBare = $bare;
                if (preg_match('/^> ?/', $bare)) {
                    $inQuote = true;
                    $bare = preg_replace('/^> ?/', '', $bare) ?? $bare;
                }
                // Bullet or DECIMAL-ordered marker, plus an optional task
                // checkbox, then the content (matches carve-js / carve-rs;
                // alpha/roman ordered markers are intentionally NOT stripped).
                $afterMarker = preg_replace(
                    '/^[ \t]*(?:[-*]|[0-9]+[.)]) +(?:\[[ xX\-_>?]\] +)?(?=\S)/',
                    '',
                    $bare,
                ) ?? $bare;
                if ($afterMarker !== $bare) {
                    $inList = true;
                    $bare = $afterMarker;
                }
            } while ($bare !== $previousBare);

            // Check for attributes that may precede a reference definition.
            // Tag the attrs with their origin context so a quoted
            // `> {.note}` cannot leak onto a top-level definition below.
            $refAttrStr = $this->parseSingleLineBlockAttributePayload($bare);
            if ($refAttrStr !== null && $refAttrStr !== '') {
                $pendingAttrs = AttributeParser::parse($refAttrStr);
                $pendingAttrsInQuote = $inQuote;
                $pendingAttrsInList = $inList;
                $i++;

                continue;
            }

            // Match reference definition: [label]: url (url can be empty, on next line)
            if (preg_match('/^\[(?!@)([^\]]+)\]:(?: +(.*)|\s*)$/', $bare, $matches)) {
                // Normalize label: collapse whitespace, trim
                $label = preg_replace('/\s+/', ' ', trim($matches[1])) ?? trim($matches[1]);
                $url = trim($matches[2] ?? '');

                $j = $i + 1;
                // A list-item definition (carve-js parity) must be complete on
                // its own line: no continuation gathering, and an empty url is
                // NOT a definition -- the list item renders it as content.
                if ($inList && $url === '') {
                    $i++;

                    continue;
                }

                // Collect continuation lines (URL can start on continuation
                // line). Only for top-level / blockquote defs; a list-item def
                // is single-line (above).
                while (!$inList && $j < $count) {
                    $nextLineRaw = $lines[$j];
                    // A blockquoted def's continuation must itself stay
                    // inside the blockquote. Strip `>` from the next
                    // line; if the strip removed no marker, the
                    // blockquote has ended -- do not absorb top-level
                    // text into the quoted URL.
                    $nextLine = $nextLineRaw;
                    if ($inQuote) {
                        // Strip `>` + optional LITERAL space per marker, exactly
                        // as blockQuoteLineContent does (a tab is left as inner
                        // content), so the prepass and the real parse agree.
                        $stripped = preg_replace('/^(?:> ?)+/', '', $nextLineRaw) ?? $nextLineRaw;
                        if ($stripped === $nextLineRaw) {
                            break;
                        }
                        $nextLine = $stripped;
                    }
                    if (IndentationHelper::isBlankLine($nextLine)) {
                        break;
                    }
                    // Check if next line starts a new reference definition
                    if (preg_match('/^\[(?!@)([^\]]+)\]: /', $nextLine)) {
                        break;
                    }
                    if ($this->startsNewBlock($nextLine)) {
                        break;
                    }
                    // A list marker (bullet or ordered, at any indent) starts a
                    // list, not a definition continuation. startsNewBlock() no
                    // longer reports list markers (symmetric interruption), so
                    // check explicitly to avoid swallowing the list.
                    if ($this->listParser->parseListItemMarker(ltrim($nextLine)) !== null) {
                        break;
                    }
                    if (preg_match('/^\s+(\S.*)$/', $nextLine, $contMatch)) {
                        $url .= $contMatch[1];
                        $j++;
                    } else {
                        break;
                    }
                }

                // Attach pendingAttrs only when the attributes line and the
                // definition share the same context (both quoted or both
                // top-level). This prevents a `> {.note}` line from leaking
                // its attrs onto a top-level `[r]: /u` below it.
                $attrsToUse = ($pendingAttrsInQuote === $inQuote && $pendingAttrsInList === $inList)
                    ? $pendingAttrs
                    : [];
                // Split a trailing quoted title: `url "title"` / `url 'title'`.
                $title = null;
                if (preg_match('/^(.*?)\s+"([^"]*)"$/', $url, $tm)) {
                    $url = $tm[1];
                    $title = $tm[2];
                } elseif (preg_match("/^(.*?)\\s+'([^']*)'$/", $url, $tm)) {
                    $url = $tm[1];
                    $title = $tm[2];
                }
                $this->references[$label] = new ReferenceDefinition(trim($url), $attrsToUse, $i, $title);
                $pendingAttrs = [];
                $pendingAttrsInQuote = false;
                $pendingAttrsInList = false;
                $i = $j;

                continue;
            }

            // Non-reference line, clear any pending attributes
            if (!IndentationHelper::isBlankLine($line)) {
                $pendingAttrs = [];
                $pendingAttrsInQuote = false;
                $pendingAttrsInList = false;
            }

            $i++;
        }
    }

    /**
     * Extract footnote definitions from the document
     *
     * @param array<string> $lines
     */
    protected function extractFootnotes(array $lines): void
    {
        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];

            // Match footnote definition: [^label]: content
            if (preg_match('/^\[\^([^\]]+)\]:(?: +(.*)|\s*)$/', $line, $matches)) {
                $label = $matches[1];
                $content = $matches[2] ?? '';

                // Determine base indentation (2 spaces for footnotes)
                $baseIndent = 2;

                // Collect continuation lines (indented or blank)
                $contentLines = [];
                if (trim($content) !== '') {
                    $contentLines[] = $content;
                }
                $j = $i + 1;
                $hasContent = false;
                while ($j < $count) {
                    $nextLine = $lines[$j];
                    if (IndentationHelper::isBlankLine($nextLine)) {
                        // Add blank line to preserve structure
                        $contentLines[] = '';
                        $j++;

                        continue;
                    }
                    // A footnote body extends only to lines indented by at least
                    // base indentation (2 spaces or a tab), per grammar PART 9
                    // §16. A line with less indentation (e.g. a single space) is
                    // a top-level block, not part of the footnote -- matches
                    // carve-js / carve-rs.
                    if (preg_match('/^(?:[ ]{' . $baseIndent . '}|\t)(.*)$/', $nextLine, $contMatch)) {
                        $contentLines[] = $contMatch[1];
                        $hasContent = true;
                        $j++;
                    } else {
                        break;
                    }
                }

                // Remove trailing blank lines
                $lineCount = count($contentLines);
                while ($lineCount > 0 && $contentLines[$lineCount - 1] === '') {
                    array_pop($contentLines);
                    $lineCount--;
                }

                $footnote = new Footnote($label);
                if ($contentLines) {
                    $this->parseBlocks($footnote, $contentLines, 0);
                }
                $this->footnotes[$label] = $footnote;
            }

            $i++;
        }
    }

    /**
     * Extract abbreviation definitions from the document
     *
     * Syntax: *[ABBR]: Full Definition Text
     *
     * This is an extension feature inspired by PHP Markdown Extra.
     *
     * @param array<string> $lines
     */
    protected function extractAbbreviations(array $lines): void
    {
        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];

            // Match abbreviation definition: *[abbr]: definition
            if (preg_match(self::ABBREVIATION_DEFINITION_PATTERN, $line, $matches)) {
                $abbr = $matches[1];
                $definition = trim($matches[2]);

                // Collect continuation lines (indented)
                $j = $i + 1;
                while ($j < $count) {
                    $nextLine = $lines[$j];
                    if (IndentationHelper::isBlankLine($nextLine)) {
                        break;
                    }
                    // Check if next line starts a new abbreviation definition
                    if ($this->isAbbreviationDefinitionLine($nextLine)) {
                        break;
                    }
                    if ($this->startsNewBlock($nextLine)) {
                        break;
                    }
                    // A list marker (bullet or ordered, at any indent) starts a
                    // list, not a definition continuation. startsNewBlock() no
                    // longer reports list markers (symmetric interruption), so
                    // check explicitly to avoid swallowing the list.
                    if ($this->listParser->parseListItemMarker(ltrim($nextLine)) !== null) {
                        break;
                    }
                    // Continuation line (indented)
                    if (preg_match('/^\s+(.+)$/', $nextLine, $contMatch)) {
                        $definition .= ' ' . $contMatch[1];
                        $j++;
                    } else {
                        break;
                    }
                }

                // Store the abbreviation (case-sensitive)
                $this->abbreviations[$abbr] = $definition;
                $i = $j;

                continue;
            }

            $i++;
        }
    }

    /**
     * Extract heading IDs as implicit reference definitions
     * This allows [Heading][] style links to headings
     *
     * @param array<string> $lines
     */
    protected function extractHeadingReferences(array $lines): void
    {
        $headingIdTracker = new HeadingIdTracker();
        $headingIdTracker->setIdTransformer($this->headingIdTransformer);
        $headingIdTracker->setLowercase($this->headingIdLowercase);
        $pendingId = null;
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            // Check for an explicit id on a block-attribute line before the
            // heading -- bare ({#custom-id}) or part of a fuller list
            // ({#id .class key=val}), single- or multi-line. Attribute lines
            // accumulate (§15, last id wins); an attribute line without an id
            // keeps a pending one. Mirrors the tryParseBlockAttributes gates
            // (first content char [.#a-zA-Z], not a comment/braced inline
            // marker) so the pre-scan accepts exactly what the parser does.
            $attrStr = $this->scanBlockAttributeLines($lines, $i, $consumed);
            if ($attrStr !== null) {
                // Same source-order merge the parser uses (later token wins,
                // e.g. `{id=bar #foo}` -> foo), so the pre-scan id always
                // matches the rendered one.
                $attrs = $attrStr === '' ? [] : AttributeParser::parseAndMerge([], $attrStr);
                if (isset($attrs['id'])) {
                    $pendingId = $attrs['id'];
                }
                $i += $consumed - 1;

                continue;
            }

            // Match heading: 1-6 # characters at column 0, followed by space(s) and content
            // Space after # is syntax delimiter, not indentation - must be space(s) per spec, not tab.
            // The marker MUST start at column 0 (no leading indent): an indented `#`-line is a
            // paragraph, matching carve-js / carve-rs and the spec grammar (heading_first_line =
            // heading_marker, space, ...).
            if (preg_match('/^(#{1,6}) +(.*\S.*)$/', $line, $matches)) {
                // Content required (same rule as tryParseHeading): a bare
                // `#` / `# ` is not a heading and must not consume a slug here.
                $headingText = trim($matches[2]);
                $level = strlen($matches[1]);

                // Collect continuation lines. This mirrors tryParseHeading so
                // the implicit-reference label agrees with the rendered id: a
                // `#`-marker continuation line folds ONLY when its marker count
                // EQUALS the open level; a different count (more OR fewer) ends
                // the heading and starts a new one.
                $j = $i + 1;
                while ($j < $count) {
                    $nextLine = $lines[$j];
                    if (trim($nextLine) === '') {
                        break;
                    }
                    if (preg_match('/^#{' . $level . '} +(.+)$/', $nextLine, $contMatch)) {
                        $headingText .= ' ' . trim($contMatch[1]);
                        $j++;

                        continue;
                    }
                    if (preg_match('/^#{1,6}/', $nextLine)) {
                        break;
                    }
                    if (!$this->startsNewBlock($nextLine)) {
                        $headingText .= ' ' . trim($nextLine);
                        $j++;
                    } else {
                        break;
                    }
                }

                // Fast path: a heading whose collected text is purely letters,
                // numbers and spaces has no inline markup, so its plain text
                // equals the raw text and its id resolves without building and
                // inline-parsing a Heading node. (Smart typography only rewrites
                // punctuation, which slugging collapses, so the id is identical.)
                // Skips one of the two inline parses per heading.
                if ($pendingId === null && $headingText !== '' && preg_match('/^[\p{L}\p{N} ]+$/u', $headingText) === 1) {
                    $label = preg_replace('/\s+/', ' ', $headingText) ?? $headingText;
                    $id = $headingIdTracker->getIdForText($label);
                    $this->headingIds[$id] = true;
                    $reference = new ReferenceDefinition('#' . $id, [], $i);
                    $this->registerHeadingReference($label, $reference);

                    continue;
                }

                $heading = new Heading(strlen($matches[1]));
                if ($pendingId !== null) {
                    $heading->setAttribute('id', $pendingId);
                    $pendingId = null;
                }
                $this->inlineParser->parseHeading($heading, $headingText, $i);

                $plainText = $headingIdTracker->getPlainText($heading);
                $id = $headingIdTracker->getIdForHeading($heading);
                $this->headingIds[$id] = true;

                // Register as reference if not already defined
                // Use normalized plain text as the label (for [Heading][] style links)
                $label = preg_replace('/\s+/', ' ', trim($plainText)) ?? $plainText;
                $reference = new ReferenceDefinition('#' . $id, [], $i);
                $this->registerHeadingReference($label, $reference);
            } else {
                // Non-heading, non-attribute line - clear pending ID
                if (!IndentationHelper::isBlankLine($line)) {
                    $pendingId = null;
                }
            }
        }
    }

    /**
     * Recognize a block-attribute line (single- or multi-line) starting at
     * $start, WITHOUT applying it. Returns the joined attribute string and
     * sets $consumed to the number of lines the block spans, or returns null
     * when the line is not a block-attribute line. Mirrors the recognition
     * rules of tryParseBlockAttributes() exactly: `{...}` with the first
     * content character in [.#a-zA-Z] (excludes braced inline markers like
     * `{=x=}` and `{%...%}` comments); a multi-line block needs indented
     * continuation lines and a closing `}`.
     *
     * @param array<string> $lines
     * @param int $start
     * @param int|null $consumed
     */
    protected function scanBlockAttributeLines(array $lines, int $start, ?int &$consumed): ?string
    {
        $consumed = 0;
        $line = $lines[$start];

        if (!str_starts_with($line, '{')) {
            return null;
        }

        // Empty attribute block {} - consumed, contributes nothing
        // (mirrors tryParseBlockAttributes).
        if (preg_match('/^\{\}\s*$/', $line)) {
            $consumed = 1;

            return '';
        }

        // Single-line block: {.class #id key=value}, including adjacent
        // blocks that merge in order: {.class}{#id}.
        $singleLineAttrStr = $this->parseSingleLineBlockAttributePayload($line);
        if ($singleLineAttrStr !== null) {
            $attrStr = $singleLineAttrStr;
            if (!preg_match('/^[.#a-zA-Z]/', $attrStr) || str_starts_with($attrStr, '%')) {
                return null;
            }
            $consumed = 1;

            return $attrStr;
        }

        // Multi-line block: { on the first line, } on a later line, with
        // indented continuation lines in between.
        $count = count($lines);
        $attrContent = substr($line, 1);
        $i = $start + 1;
        while ($i < $count) {
            $nextLine = $lines[$i];
            if (preg_match('/^(.*)\}\s*$/', $nextLine, $closeMatch)) {
                $attrStr = trim($attrContent . ' ' . $closeMatch[1]);
                if (!preg_match('/^[.#a-zA-Z]/', $attrStr) || str_starts_with($attrStr, '%')) {
                    return null;
                }
                $consumed = $i - $start + 1;

                return $attrStr;
            }
            if (preg_match('/^\s+(.*)$/', $nextLine, $contMatch)) {
                $attrContent .= ' ' . $contMatch[1];
                $i++;
            } else {
                return null;
            }
        }

        return null;
    }

    /**
     * Recurse into block content, but cap nesting depth so pathologically
     * nested input degrades to literal text instead of overflowing the stack
     * (or exhausting memory). See MAX_NESTING_DEPTH.
     *
     * @param \Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $indent
     * @param bool $topLevel
     */
    protected function parseBlocks(Node $parent, array $lines, int $indent, bool $topLevel = false): void
    {
        if ($this->nestingDepth >= self::MAX_NESTING_DEPTH) {
            $text = implode("\n", $lines);
            if (trim($text) !== '') {
                $paragraph = new Paragraph();
                $this->inlineParser->parse($paragraph, $text);
                $parent->appendChild($paragraph);
            }

            return;
        }

        $this->nestingDepth++;
        try {
            $this->parseBlocksImpl($parent, $lines, $indent, $topLevel);
        } finally {
            $this->nestingDepth--;
        }
    }

    /**
     * @param \Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $indent
     * @param bool $topLevel
     */
    private function parseBlocksImpl(Node $parent, array $lines, int $indent, bool $topLevel = false): void
    {
        $i = 0;
        $count = count($lines);

        // Precompute, once for this line set, the longest colon-fence CLOSER
        // (a colon-only `:::` line outside a nested code block) at or after each
        // index. tryParseDiv consults this in O(1) to decide whether an opener
        // has a closer ahead, instead of rescanning to EOF per opener -- which
        // made a document of many unterminated `:::` openers O(n²).
        $divCloserSuffix = $this->buildDivCloserSuffixMax($lines);

        while ($i < $count) {
            $line = $lines[$i];

            // Skip blank lines
            if (IndentationHelper::isBlankLine($line)) {
                $i++;

                continue;
            }

            // Try to parse block attributes first
            $attrConsumed = $this->tryParseBlockAttributes($lines, $i);
            if ($attrConsumed !== null) {
                $i += $attrConsumed;

                continue;
            }

            // A bare `---` at the very start of the document is ambiguous between
            // a thematic break and the opening of bare frontmatter (`---\n…\n---`).
            // Give registered block matchers first refusal at this one position so
            // the frontmatter extension can capture the block raw, before core reads
            // the `---` as a thematic break (which would route the body through
            // inline parsing and corrupt quotes/dashes/ellipses). Scoped to the
            // exact `---` opener so core-first still holds for every other line and
            // every other thematic-break shape (***, ___, ----); a lone `---` with
            // no closing fence is declined, leaving it a thematic break.
            if ($topLevel && !$parent->hasChildren() && preg_match('/^---\s*$/', $line) === 1) {
                $matchConsumed = $this->tryBlockMatchers($parent, $lines, $i);
                if ($matchConsumed !== null) {
                    $i += $matchConsumed;

                    continue;
                }
            }

            // Fast path: a line whose first non-blank char is a LETTER can only
            // be a paragraph, a custom block matcher, or an alphabetic/roman
            // ordered list (`a.`, `iv.`) - every other core block opener begins
            // with punctuation or a digit. So skip the ~16-probe tryParse
            // cascade and try only the list parser before falling through. This
            // is the common case (prose) and the dominant per-line cost in PHP,
            // where each preg_match carries real call overhead. (A first-char
            // switch over the punctuation openers was tried too but added no
            // measurable gain - for those lines the actual parsing, not the
            // dispatch probes, dominates - so only the prose fast path is kept.)
            $ws = strspn($line, " \t");
            $fc = $line[$ws] ?? '';
            if ($fc !== '' && ($fc >= 'a' && $fc <= 'z' || $fc >= 'A' && $fc <= 'Z')) {
                $consumed = $this->tryParseList($parent, $lines, $i)
                    ?? $this->tryBlockMatchers($parent, $lines, $i)
                    ?? $this->tryParseParagraph($parent, $lines, $i);
                $i += $consumed;

                continue;
            }

            // Try to match block elements in order of precedence
            // Fenced comment must come before thematic break (%%% vs ---)
            // Comment and raw block must come before code block since ``` =format is a special case
            // Caption must come before paragraph to catch `^ caption text`
            $consumed = $this->tryParseFencedComment($parent, $lines, $i)
                ?? $this->tryParseComment($parent, $lines, $i)
                ?? $this->tryParseRawBlock($parent, $lines, $i)
                ?? $this->tryParseCodeBlock($parent, $lines, $i)
                ?? $this->tryParseLineBlock($parent, $lines, $i)
                ?? $this->tryParseHardBreaksBlock($parent, $lines, $i, $divCloserSuffix)
                ?? $this->tryParseDiv($parent, $lines, $i, $divCloserSuffix)
                ?? $this->tryParseDefinitionList($parent, $lines, $i)
                ?? $this->tryParseHeading($parent, $lines, $i)
                ?? $this->tryParseThematicBreak($parent, $line, $i)
                ?? $this->tryParseBlockQuote($parent, $lines, $i)
                ?? $this->tryParseList($parent, $lines, $i)
                ?? $this->tryParseTable($parent, $lines, $i)
                ?? $this->tryParseFootnoteDefinition($lines, $i)
                ?? $this->tryParseReferenceDefinition($lines, $i)
                ?? $this->tryParseAbbreviationDefinition($lines, $i)
                ?? $this->tryParseCaption($parent, $lines, $i);

            if ($consumed === null) {
                $matchConsumed = $this->tryBlockMatchers($parent, $lines, $i);
                if ($matchConsumed !== null) {
                    $i += $matchConsumed;

                    continue;
                }
            }

            $consumed ??= $this->tryParseParagraph($parent, $lines, $i);

            $i += $consumed;
        }
    }

    /**
     * @param \Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryBlockMatchers(Node $parent, array $lines, int $start): ?int
    {
        if ($this->blockMatchers === []) {
            return null;
        }

        $previousParent = $this->currentMatcherParent;
        $this->currentMatcherParent = $parent;
        $ctx = new MatcherContext($this, $this->getInlineParser());
        try {
            foreach ($this->sortedBlockMatchers() as $matcher) {
                $result = $matcher($lines, $start, $ctx);
                if ($result === null) {
                    continue;
                }
                // Legacy addBlockPattern callbacks append to $parent themselves
                // and report only the line count.
                if (is_int($result)) {
                    return $result;
                }
                // Normative matchers return the node for the dispatcher to append.
                $parent->appendChild($result['node']);

                return $result['linesConsumed'];
            }
        } finally {
            $this->currentMatcherParent = $previousParent;
        }

        return null;
    }

    /**
     * Try to parse block attributes {.class #id key=value}
     *
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseBlockAttributes(array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Must start with {
        if (!str_starts_with($line, '{')) {
            return null;
        }

        // Check for empty attribute block {} - just skip it
        if (preg_match('/^\{\}\s*$/', $line)) {
            return 1;
        }

        // Check for single-line attribute: {.class}, {#id}, {key=value}, or
        // adjacent blocks like {.class}{#id}.
        $singleLineAttrStr = $this->parseSingleLineBlockAttributePayload($line);
        if ($singleLineAttrStr !== null) {
            $attrStr = $singleLineAttrStr;
            // Exclude _ * = + - ~ ^ which are braced inline markers (not block attributes)
            // Exclude % which starts comments (handled by tryParseComment)
            if (!preg_match('/^[.#a-zA-Z]/', $attrStr) || str_starts_with($attrStr, '%')) {
                return null;
            }

            // The whole payload must be a valid attribute block, else it is
            // not a block-attribute line (§14) and stays literal paragraph
            // text. One invalid name (`.123`, `#1`, `2=v`, `.a!b`) -- even
            // mixed with valid ones (`.ok .1`) -- invalidates the whole line,
            // matching the inline path and carve-js.
            if (!$this->inlineParser->isValidAttrPayload($attrStr)) {
                return null;
            }

            // Check if attributes precede a reference definition - if so, skip storing them
            // (they were already applied during extractReferences)
            $count = count($lines);
            $nextIdx = $start + 1;
            while ($nextIdx < $count && IndentationHelper::isBlankLine($lines[$nextIdx])) {
                $nextIdx++;
            }
            if ($nextIdx < $count && preg_match('/^\[([^\]]+)\]: /', $lines[$nextIdx])) {
                // Attributes precede a reference definition, don't store them as block attrs
                return 1;
            }

            $this->parseAttributeString($attrStr);

            return 1;
        }

        // Try multi-line attributes: { on first line, } on a later line
        // Collect lines until we find the closing }
        $count = count($lines);
        $attrContent = substr($line, 1); // Remove opening {
        $i = $start + 1;

        while ($i < $count) {
            $nextLine = $lines[$i];

            // Check if this line ends the attribute block
            if (preg_match('/^(.*)\}\s*$/', $nextLine, $closeMatch)) {
                $attrContent .= ' ' . $closeMatch[1];
                $attrStr = trim($attrContent);

                // Exclude _ * = + - ~ ^ which are braced inline markers (not block attributes)
                // Exclude % which starts comments (handled by tryParseComment)
                if (!preg_match('/^[.#a-zA-Z]/', $attrStr) || str_starts_with($attrStr, '%')) {
                    return null;
                }
                // The whole payload must be valid, else it is not a block-
                // attribute line (§14) and stays literal. One invalid name --
                // even mixed with valid ones -- invalidates the whole line.
                if (!$this->inlineParser->isValidAttrPayload($attrStr)) {
                    return null;
                }
                $this->parseAttributeString($attrStr);

                return $i - $start + 1;
            }

            // Continuation line (must be indented)
            if (preg_match('/^\s+(.*)$/', $nextLine, $contMatch)) {
                $attrContent .= ' ' . $contMatch[1];
                $i++;
            } else {
                // Not a valid continuation
                return null;
            }
        }

        return null;
    }

    /**
     * Parse attribute string and add to pending attributes
     */
    protected function parseAttributeString(string $attrStr): void
    {
        $this->pendingAttributes = AttributeParser::parseAndMerge($this->pendingAttributes, $attrStr);
    }

    /**
     * Apply pending attributes to a node and clear them
     */
    protected function applyPendingAttributes(Node $node): void
    {
        if ($this->pendingAttributes !== []) {
            $node->setAttributes($this->pendingAttributes);
            $this->pendingAttributes = [];
        }
    }

    /**
     * Consume and return pending block attributes
     *
     * This allows custom block pattern callbacks to retrieve any block attributes
     * that were defined on the line(s) before the block started. The attributes
     * are cleared after retrieval.
     *
     * Example usage in a custom block callback:
     * ```php
     * $parser->addBlockPattern('/^---(\w+)/', function($lines, $start, $parent, $parser) {
     *     $myNode = new MyCustomNode();
     *     $attrs = $parser->consumePendingAttributes();
     *     if (!empty($attrs)) {
     *         $myNode->setAttributes($attrs);
     *     }
     *     $parent->appendChild($myNode);
     *     return 1;
     * });
     * ```
     *
     * @return array<string, string> The pending attributes (empty array if none)
     */
    public function consumePendingAttributes(): array
    {
        $attrs = $this->pendingAttributes;
        $this->pendingAttributes = [];

        return $attrs;
    }

    /**
     * @param \Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseCodeBlock(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Use FencedBlockParser to detect code fence opener
        $fenceInfo = $this->fencedBlockParser->parseCodeFenceOpener($line);
        if ($fenceInfo === null) {
            return null;
        }

        $fenceChar = $fenceInfo['char'];
        $fenceLength = $fenceInfo['length'];
        $info = $fenceInfo['info'];
        $header = $fenceInfo['header'];
        $label = $fenceInfo['label'];
        $indentLen = strlen($fenceInfo['indent']);

        $content = '';
        $i = $start + 1;
        $count = count($lines);
        $closed = false;

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Check for closing fence
            if ($this->fencedBlockParser->isCodeFenceCloser($currentLine, $fenceChar, $fenceLength)) {
                $i++;
                $closed = true;

                break;
            }

            // Remove indent from content lines (up to the same amount as opening fence)
            $currentLine = $this->fencedBlockParser->removeIndent($currentLine, $indentLen);

            $content .= $currentLine . "\n";
            $i++;
        }

        // A fence opener reaching this point is at block start (no open
        // paragraph): a mid-paragraph unterminated fence never gets here -- the
        // §10 closer-lookahead keeps it inside the paragraph as an inline
        // verbatim run. So an unclosed opener is always a block code fence that
        // runs to the end of the block, even when empty (matching carve-js /
        // canonical djot), rather than degrading to an inline code span.
        if (!$closed) {
            $this->addWarning('Unclosed code fence', $start, 1, true);
        }

        $language = $info !== '' ? $info : null;

        // Drop only the line separator before the closing fence. Code content
        // itself, including blank lines, is preserved verbatim.
        if (str_ends_with($content, "\n")) {
            $content = substr($content, 0, -1);
        }

        $codeBlock = new CodeBlock($content, $language, $label);
        $this->applyPendingAttributes($codeBlock);
        // The opener "header" becomes the <pre> title attribute (rendering A),
        // unless a preceding {title=...} block-attribute line already set one
        // (the explicit attribute channel wins).
        if ($header !== null && !$codeBlock->hasAttribute('title')) {
            $codeBlock->setAttribute('title', $header);
        }
        $parent->appendChild($codeBlock);

        return $i - $start;
    }

    /**
     * Try to parse a comment block {% ... %}
     *
     * @param \Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseComment(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Carve line comment: a `%%` line (the `%%%` fenced form is handled
        // earlier by tryParseFencedComment) runs to end of line, not rendered.
        if (str_starts_with(ltrim($line), '%%')) {
            $parent->appendChild(new Comment(trim(substr(ltrim($line), 2))));

            return 1;
        }

        // Use FencedBlockParser to check for comment opener
        if (!$this->fencedBlockParser->isCommentOpener($line)) {
            return null;
        }

        $content = '';
        $i = $start;
        $count = count($lines);
        $inComment = false;
        $closed = false;

        while ($i < $count) {
            $currentLine = $lines[$i];

            if (!$inComment) {
                // Look for opening {%
                $openPos = strpos($currentLine, '{%');
                if ($openPos !== false) {
                    $inComment = true;
                    $afterOpen = substr($currentLine, $openPos + 2);
                    // Check if closing is on same line
                    $closePos = strpos($afterOpen, '%}');
                    if ($closePos !== false) {
                        $content .= substr($afterOpen, 0, $closePos);
                        $i++;
                        $closed = true;

                        break;
                    }
                    $content .= $afterOpen . "\n";
                }
            } else {
                // Look for closing %}
                $closePos = strpos($currentLine, '%}');
                if ($closePos !== false) {
                    $content .= substr($currentLine, 0, $closePos);
                    $i++;
                    $closed = true;

                    break;
                }
                $content .= $currentLine . "\n";
            }

            $i++;
        }

        if (!$closed) {
            $this->addWarning('Unclosed comment', $start, 1, true);
        }

        // Comments are stored but not rendered
        $comment = new Comment(trim($content));
        $parent->appendChild($comment);

        return $i - $start;
    }

    /**
     * Try to parse a fenced comment block %%% ... %%%
     *
     * This is an extension that allows multi-line comments with blank lines,
     * which the standard {% %} syntax cannot handle.
     *
     * @param \Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseFencedComment(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        $fenceInfo = $this->fencedBlockParser->parseFencedCommentOpener($line);
        if ($fenceInfo === null) {
            return null;
        }

        $fenceLength = $fenceInfo['length'];
        $contentLines = [];
        $i = $start + 1;
        $count = count($lines);
        $closed = false;

        while ($i < $count) {
            $currentLine = $lines[$i];

            if ($this->fencedBlockParser->isFencedCommentCloser($currentLine, $fenceLength)) {
                $closed = true;
                $i++;

                break;
            }

            $contentLines[] = $currentLine;
            $i++;
        }

        if (!$closed) {
            $this->addWarning('Unclosed fenced comment', $start, 1, true);
        }

        // Trim trailing empty lines but preserve internal blank lines
        while ($contentLines && trim(end($contentLines)) === '') {
            array_pop($contentLines);
        }

        $content = implode("\n", $contentLines);

        // Comments are stored but not rendered
        $comment = new Comment(trim($content));
        $parent->appendChild($comment);

        return $i - $start;
    }

    /**
     * Try to parse a raw block ``` =format
     *
     * @param \Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseRawBlock(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Use FencedBlockParser to detect raw block opener
        $rawInfo = $this->fencedBlockParser->parseRawBlockOpener($line);
        if ($rawInfo === null) {
            return null;
        }

        $fenceLength = $rawInfo['length'];
        $format = $rawInfo['format'];
        // The closer must use the SAME fence character as the opener (` or ~);
        // a ~~~=html block closes with ~~~, not ```.
        $fenceChar = $rawInfo['fence'][0];

        $content = '';
        $i = $start + 1;
        $count = count($lines);
        $closed = false;

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Check for closing fence (equal or longer)
            if ($this->fencedBlockParser->isCodeFenceCloser($currentLine, $fenceChar, $fenceLength)) {
                $i++;
                $closed = true;

                break;
            }

            $content .= $currentLine . "\n";
            $i++;
        }

        if (!$closed) {
            $this->addWarning('Unclosed raw block', $start, 1, true);
        }

        $rawBlock = new RawBlock(trim($content, "\n"), $format);
        $this->applyPendingAttributes($rawBlock);
        $parent->appendChild($rawBlock);

        return $i - $start;
    }

    /**
     * @param \Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */

    /**
     * @param \Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     * @param array<int, int> $divCloserSuffix Longest colon-fence closer at or
     *   after each index (from buildDivCloserSuffixMax), for an O(1) closer check.
     */
    protected function tryParseDiv(Node $parent, array $lines, int $start, array $divCloserSuffix): ?int
    {
        $line = $lines[$start];

        // Use FencedBlockParser to detect div opener
        $divInfo = $this->fencedBlockParser->parseDivFenceOpener($line);
        if ($divInfo === null) {
            return null;
        }

        $fenceLength = $divInfo['length'];
        $className = $divInfo['className'];
        $label = $divInfo['label'];

        // A colon fence opens only when a matching closer (a `:::` line of
        // equal-or-greater length, not inside a nested code block) exists
        // ahead. An unterminated `:::` / `::: note` stays literal -- it parses
        // as ordinary blocks instead of swallowing the rest of the document
        // (grammar §12; matches carve-js / carve-rs). A consequence: a
        // SAME-length inner fence closes the outer div, so nested divs need an
        // outer fence longer than the inner (also matching js / rs / djot).
        // The precomputed suffix-max gives the closer test in O(1); checked
        // before any state is touched (pending attributes, the div node) so a
        // failed opener leaves them for the next parser.
        if (($divCloserSuffix[$start + 1] ?? 0) < $fenceLength) {
            return null;
        }

        // STRICT (djot): the opener carries no inline attributes, so
        // `parseDivFenceOpener` has already guaranteed `$className` is empty
        // (bare `:::`), a type word, or `type "title"`. Split off the
        // optional quoted title; the type word is the div's primary class.
        // Attributes attach via a preceding block-attribute line only.
        $title = null;
        if ($className !== '' && preg_match('/^([a-zA-Z_][\w-]*)(?:\s+"([^"]*)")?$/', $className, $tm) === 1) {
            $className = $tm[1];
            $title = $tm[2] ?? null;
        }

        $div = new Div();

        // The opener `[label]` is inert structured metadata (NOT rendered); a
        // group extension (tabs) reads it as the tab name. Mirrors a code
        // fence's `[label]` on CodeBlock.
        if ($label !== null) {
            $div->setLabel($label);
        }

        // Leading block-attribute lines (`{.x}` before the opener) are the
        // only attribute source; they apply to the div in source order.
        if ($className !== '') {
            $div->addClass($className);
        }
        if ($title !== null) {
            $div->setAttribute('title', $title);
        }
        foreach ($this->pendingAttributes as $name => $value) {
            if ($name === 'class') {
                foreach (preg_split('/\s+/', trim((string)$value)) ?: [] as $class) {
                    if ($class !== '') {
                        $div->addClass($class);
                    }
                }
            } else {
                $div->setAttribute($name, $value);
            }
        }
        $this->pendingAttributes = [];

        $innerLines = [];
        $i = $start + 1;
        $count = count($lines);
        $closed = false;
        $inCodeBlock = false;
        $codeBlockFence = '';
        $codeBlockFenceLength = 0;

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Track code blocks so we don't mistake ::: inside code blocks as
            // closing fences. A raw ``` =format block is tracked the same way,
            // but only when it really closes ahead: an unclosed ``` =format is
            // inline code in a paragraph, not a verbatim region, so it must not
            // swallow a later ::: as the div's closing fence.
            if (!$inCodeBlock) {
                $rawFenceInfo = $this->fencedBlockParser->parseRawBlockOpener($currentLine);
                if (
                    $rawFenceInfo !== null
                    && $this->hasCodeFenceCloserAhead($lines, $i, $rawFenceInfo['fence'][0], $rawFenceInfo['length'])
                ) {
                    $inCodeBlock = true;
                    $codeBlockFence = $rawFenceInfo['fence'][0];
                    $codeBlockFenceLength = $rawFenceInfo['length'];
                    $innerLines[] = $currentLine;
                    $i++;

                    continue;
                }
                $codeFenceInfo = $this->fencedBlockParser->parseCodeFenceOpener($currentLine);
                if ($codeFenceInfo !== null) {
                    $inCodeBlock = true;
                    $codeBlockFence = $codeFenceInfo['char'];
                    $codeBlockFenceLength = $codeFenceInfo['length'];
                    $innerLines[] = $currentLine;
                    $i++;

                    continue;
                }
            }
            if ($inCodeBlock) {
                // Check for closing code fence
                if ($this->fencedBlockParser->isCodeFenceCloser($currentLine, $codeBlockFence, $codeBlockFenceLength)) {
                    $inCodeBlock = false;
                }
                $innerLines[] = $currentLine;
                $i++;

                continue;
            }

            // Check for closing fence (equal or longer) - only when not in code block
            if ($this->fencedBlockParser->isDivFenceCloser($currentLine, $fenceLength)) {
                $i++;
                $closed = true;

                break;
            }

            $innerLines[] = $currentLine;
            $i++;
        }

        if (!$closed) {
            $this->addWarning('Unclosed div', $start, 1, true);
        }

        // Parse inner content as blocks (track line offset for nested content)
        $previousOffset = $this->lineOffset;
        $this->lineOffset = $previousOffset + $start + 1;
        $this->parseBlocks($div, $innerLines, 0);
        $this->lineOffset = $previousOffset;

        // (Pending block attributes were already applied before the
        // opener's own attributes above, per PART 9 §15 precedence.)
        $parent->appendChild($div);

        return $i - $start;
    }

    /**
     * Try to parse a local hard-break container (`::: \`).
     *
     * Unlike `::: |` line blocks, this parses ordinary block content and only
     * upgrades soft breaks in direct paragraph children. Nested blocks keep their
     * normal soft-break behavior.
     *
     * @param \Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     * @param array<int, int> $divCloserSuffix
     */
    protected function tryParseHardBreaksBlock(Node $parent, array $lines, int $start, array $divCloserSuffix): ?int
    {
        $line = $lines[$start];
        if (preg_match('/^(?<fence>:{3,})[ \t]+\\\\[ \t]*$/', $line, $matches) !== 1) {
            return null;
        }

        $fenceLength = strlen($matches['fence']);
        if (($divCloserSuffix[$start + 1] ?? 0) < $fenceLength) {
            return null;
        }

        $div = new Div();
        $div->addClass('hardbreaks');
        foreach ($this->pendingAttributes as $name => $value) {
            if ($name === 'class') {
                foreach (preg_split('/\s+/', trim((string)$value)) ?: [] as $class) {
                    if ($class !== '') {
                        $div->addClass($class);
                    }
                }
            } else {
                $div->setAttribute($name, $value);
            }
        }
        $this->pendingAttributes = [];

        $innerLines = [];
        $i = $start + 1;
        $count = count($lines);
        $closed = false;
        $inCodeBlock = false;
        $codeBlockFence = '';
        $codeBlockFenceLength = 0;

        while ($i < $count) {
            $currentLine = $lines[$i];

            // See tryParseDiv: track raw ``` =format blocks too (only when
            // closed ahead), so a bare ::: inside one is not taken as the
            // closing div fence.
            if (!$inCodeBlock) {
                $rawFenceInfo = $this->fencedBlockParser->parseRawBlockOpener($currentLine);
                if (
                    $rawFenceInfo !== null
                    && $this->hasCodeFenceCloserAhead($lines, $i, $rawFenceInfo['fence'][0], $rawFenceInfo['length'])
                ) {
                    $inCodeBlock = true;
                    $codeBlockFence = $rawFenceInfo['fence'][0];
                    $codeBlockFenceLength = $rawFenceInfo['length'];
                    $innerLines[] = $currentLine;
                    $i++;

                    continue;
                }
                $codeFenceInfo = $this->fencedBlockParser->parseCodeFenceOpener($currentLine);
                if ($codeFenceInfo !== null) {
                    $inCodeBlock = true;
                    $codeBlockFence = $codeFenceInfo['char'];
                    $codeBlockFenceLength = $codeFenceInfo['length'];
                    $innerLines[] = $currentLine;
                    $i++;

                    continue;
                }
            }
            if ($inCodeBlock) {
                if ($this->fencedBlockParser->isCodeFenceCloser($currentLine, $codeBlockFence, $codeBlockFenceLength)) {
                    $inCodeBlock = false;
                }
                $innerLines[] = $currentLine;
                $i++;

                continue;
            }

            if ($this->fencedBlockParser->isDivFenceCloser($currentLine, $fenceLength)) {
                $i++;
                $closed = true;

                break;
            }

            $innerLines[] = $currentLine;
            $i++;
        }

        if (!$closed) {
            return null;
        }

        $previousOffset = $this->lineOffset;
        $this->lineOffset = $previousOffset + $start + 1;
        $this->parseBlocks($div, $innerLines, 0);
        $this->lineOffset = $previousOffset;

        $this->convertDirectParagraphSoftBreaksToHardBreaks($div);
        $parent->appendChild($div);

        return $i - $start;
    }

    protected function convertDirectParagraphSoftBreaksToHardBreaks(Div $div): void
    {
        foreach ($div->getChildren() as $child) {
            if (!$child instanceof Paragraph) {
                continue;
            }

            foreach ($child->getChildren() as $index => $inline) {
                if ($inline instanceof SoftBreak) {
                    $child->replaceChild($index, new HardBreak());
                }
            }
        }
    }

    /**
     * Build, for one line set, the longest colon-fence CLOSER length at or
     * after each index -- a line that is only colons (`:::`, after trimming),
     * NOT inside a nested fenced code block. `$result[$i]` is the maximum such
     * length at any line `>= $i` (0 if none). A div opener of length L at
     * index `s` then has a matching closer ahead iff `$result[$s + 1] >= L`,
     * an O(1) test that replaces a per-opener rescan to EOF (grammar §12).
     *
     * @param array<string> $lines
     *
     * @return array<int, int>
     */
    protected function buildDivCloserSuffixMax(array $lines): array
    {
        $count = count($lines);
        $closerLen = array_fill(0, $count + 1, 0);
        $inCodeBlock = false;
        $codeBlockFence = '';
        $codeBlockFenceLength = 0;
        for ($i = 0; $i < $count; $i++) {
            $currentLine = $lines[$i];
            if (!$inCodeBlock) {
                // A raw ``` =format block must be tracked too: its opener is not
                // a code-fence opener (the `=` leading token is declined), but
                // its bare ``` closer WOULD be mistaken for a code-fence opener,
                // flipping $inCodeBlock and swallowing every following ::: closer
                // -- which makes later divs after a raw block parse as literal
                // paragraphs. Track it only when it really CLOSES ahead: an
                // unclosed ``` =format is just paragraph text (an inline code
                // run), so it must NOT hide a later ::: div closer (matches the
                // reference parser, which opens the div in that case).
                $rawFenceInfo = $this->fencedBlockParser->parseRawBlockOpener($currentLine);
                if (
                    $rawFenceInfo !== null
                    && $this->hasCodeFenceCloserAhead($lines, $i, $rawFenceInfo['fence'][0], $rawFenceInfo['length'])
                ) {
                    $inCodeBlock = true;
                    $codeBlockFence = $rawFenceInfo['fence'][0];
                    $codeBlockFenceLength = $rawFenceInfo['length'];

                    continue;
                }
                $codeFenceInfo = $this->fencedBlockParser->parseCodeFenceOpener($currentLine);
                if ($codeFenceInfo !== null) {
                    $inCodeBlock = true;
                    $codeBlockFence = $codeFenceInfo['char'];
                    $codeBlockFenceLength = $codeFenceInfo['length'];

                    continue;
                }
            }
            if ($inCodeBlock) {
                if ($this->fencedBlockParser->isCodeFenceCloser($currentLine, $codeBlockFence, $codeBlockFenceLength)) {
                    $inCodeBlock = false;
                }

                continue;
            }
            // A closer is a line whose trimmed content is only colons (3+).
            $trimmed = trim($currentLine);
            if ($trimmed !== '' && strlen($trimmed) >= 3 && strspn($trimmed, ':') === strlen($trimmed)) {
                $closerLen[$i] = strlen($trimmed);
            }
        }

        // Suffix-max so an opener can look up the deepest closer ahead in O(1).
        $suffix = array_fill(0, $count + 1, 0);
        for ($i = $count - 1; $i >= 0; $i--) {
            $suffix[$i] = max($closerLen[$i], $suffix[$i + 1]);
        }

        return $suffix;
    }

    /**
     * Whether a code fence opened at `$openIndex` (with the given fence char and
     * length) has a matching closer on a later line. Used to decide whether a
     * raw ``` =format opener really forms a closed verbatim region -- an
     * UNCLOSED raw fence is paragraph text (inline code), not a block that
     * should hide following ::: div closers from the closer-lookahead scans.
     *
     * @param array<string> $lines
     * @param int $openIndex
     * @param string $fenceChar
     * @param int $fenceLength
     */
    protected function hasCodeFenceCloserAhead(array $lines, int $openIndex, string $fenceChar, int $fenceLength): bool
    {
        $count = count($lines);
        for ($j = $openIndex + 1; $j < $count; $j++) {
            if ($this->fencedBlockParser->isCodeFenceCloser($lines[$j], $fenceChar, $fenceLength)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseHeading(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Fast early exit: headings start with # (possibly after up to 3 spaces)
        $trimmed = ltrim($line, ' ');
        if (!isset($trimmed[0]) || $trimmed[0] !== '#') {
            return null;
        }

        // A heading is 1-6 `#` at COLUMN 0 (no leading indent), a literal space,
        // then NON-EMPTY content (grammar `heading_first_line = heading_marker,
        // space, inline_content`). The marker must start at column 0: an indented
        // `#`-line is a paragraph, matching carve-js / carve-rs and the spec.
        // Requiring content in the pattern itself means a bare `#`, `##`, or `# `
        // is ordinary paragraph text. `# \tx` (content after a tab) is still a
        // heading: `.*\S.*` only requires a non-whitespace char after the space.
        if (!preg_match('/^(#{1,6}) +(.*\S.*)$/', $line, $matches)) {
            return null;
        }

        $level = strlen($matches[1]);
        $content = trim($matches[2]);

        // Collect continuation lines
        $i = $start + 1;
        $count = count($lines);
        while ($i < $count) {
            $nextLine = $lines[$i];

            // Empty line ends the heading
            if (IndentationHelper::isBlankLine($nextLine)) {
                break;
            }

            // Check for continuation with # prefix (SAME level only) - these
            // continue the heading. e.g., "# Heading\n# more" becomes
            // "Heading\nmore" for a level-1 heading. A `#`-marker line whose
            // marker count DIFFERS from the open level (more OR fewer) ends the
            // heading and starts a new one (handled by the next branch), per
            // Djot: only an exactly-equal marker count folds.
            if (preg_match('/^#{' . $level . '} +(.+)$/', $nextLine, $contMatch)) {
                // The heading already has non-empty first-line content, so a
                // newline always precedes a folded continuation.
                $content .= "\n" . $contMatch[1];
                $i++;
            } elseif (preg_match('/^#{1,6}(?: |$)/', $nextLine)) {
                // A `#`-marker line (any level, including a bare `#` / `# `)
                // ENDS the open heading -- matching carve-js, whose heading
                // continuation breaks on `/^#{1,6}([ \t]|$)/`. The bare-`#`
                // line then forms its own paragraph (it is not itself a
                // heading).
                break;
            } elseif (preg_match('/^\^ /', $nextLine) || preg_match('/^%{3,}/', $nextLine)) {
                // A caption (`^ `) or a fenced comment (`%%%`) ends the heading.
                break;
            } elseif (
                preg_match('/^\[[^\]]+\]: /', $nextLine)
                || $this->isAbbreviationDefinitionLine($nextLine)
                || preg_match('/^%%/', $nextLine)
                || (preg_match('/^\{(.+)\}\s*$/', $nextLine, $invisibleAttr)
                    && $this->inlineParser->isValidAttrPayload($invisibleAttr[1]))
            ) {
                // Invisible constructs -- a reference / footnote / abbreviation
                // definition, a comment, or a block-attribute line -- are §10
                // interrupters (INVISIBLE CONSTRUCTS): each ends the heading and
                // is consumed or floated forward by its own parser, exactly as it
                // interrupts a paragraph. Matches carve-js / carve-rs.
                break;
            } elseif ($this->endsHeadingOrQuote($nextLine, $lines, $i)) {
                // A block-opener ends the heading and starts that block (§10). A
                // LIST marker (bullet OR ordered) also ends the heading and starts
                // a sibling list: a list marker folds only into a PARAGRAPH, not a
                // heading (symmetric, matches carve-js / carve-rs / djot). Only
                // plain text folds into the heading.
                break;
            } else {
                // Plain text folds into the heading text.
                $content .= "\n" . $nextLine;
                $i++;
            }
        }

        $heading = new Heading($level);

        // djot-strict (spec PART 2 headings; matches carve-js #153): a heading
        // line carries NO trailing `{...}` attribute block -- a trailing brace
        // block is ordinary inline content, and the heading id derives from
        // the full literal text. Attributes attach via a PRECEDING
        // block-attribute line (applyPendingAttributes below, PART 9 §15).
        $content = trim($content);

        $this->inlineParser->parseHeading($heading, $content, $start);
        $this->applyPendingAttributes($heading);
        $parent->appendChild($heading);

        return $i - $start;
    }

    protected function tryParseThematicBreak(Node $parent, string $line, int $start): ?int
    {
        // Match thematic break: 3+ * or - characters (with optional spaces between)
        // Examples: ***, ---, * * *, - - -, *-*-*-*, **   **
        $stripped = preg_replace('/\s+/', '', $line);
        if ($stripped === null || strlen($stripped) < 3) {
            return null;
        }

        // Must contain only thematic-break markers: -, *, or _ (§ grammar
        // thematic_break). strlen(>= 3) is already checked above.
        if (!preg_match('/^[\*\-_]+$/', $stripped)) {
            return null;
        }

        $char = $stripped[0];
        $thematicBreak = new ThematicBreak($char);
        $this->applyPendingAttributes($thematicBreak);
        $parent->appendChild($thematicBreak);

        return 1;
    }

    /**
     * Block-quote line content: the text after a `> ` prefix, '' for a lone
     * `>`, or null when the line does not open/continue a block quote.
     * Byte-equivalent to the `/^> (.*)$/` and `/^>$/` regexes -- a space is
     * required after `>`; `>text` and `>\t` do not start a quote.
     */
    private function blockQuoteLineContent(string $line): ?string
    {
        if (($line[0] ?? '') !== '>') {
            return null;
        }
        if ($line === '>') {
            return '';
        }
        // The space after `>` is OPTIONAL (grammar `blockquote_line = '>',
        // [' '], inline_content`): `>tight` is a quote of `tight`, and `>>x`
        // nests. Consume one optional leading space.
        if (($line[1] ?? '') === ' ') {
            return substr($line, 2);
        }

        return substr($line, 1);
    }

    /**
     * @param \Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseBlockQuote(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Match block quote opener via byte checks (equivalent to the regexes
        // `/^> (.*)$/` and `/^>$/`, without per-line preg_match overhead): `> `
        // with content, or a lone `>`. `>text` / `>\t` are not a quote.
        $content = $this->blockQuoteLineContent($line);
        if ($content === null) {
            return null;
        }

        $blockQuote = new BlockQuote();

        // Save and clear pending attributes - they apply to the blockquote, not inner content
        $quoteAttributes = $this->pendingAttributes;
        $this->pendingAttributes = [];

        $innerLines = [];
        $lazyState = [
            'inFence' => false,
            'fenceChar' => '',
            'fenceLength' => 0,
            'inComment' => false,
            'commentLength' => 0,
            'paragraphOpen' => false,
            'paragraphTextOpen' => false,
        ];

        $innerLines[] = $content;
        $this->trackBlockQuoteLazyState($content, $lazyState);

        $i = $start + 1;
        $count = count($lines);

        while ($i < $count) {
            $currentLine = $lines[$i];

            if (IndentationHelper::isBlankLine($currentLine)) {
                break;
            }

            // Continuation marker (Carve, PART 9 §17): a lone `+` at column 0
            // after a quoted line attaches the FOLLOWING flush-left block to the
            // quote -- the un-prefixed analogue of the list-item form, so a real
            // block (list, fenced code, table, ...) can join the quote without
            // repeating `>`. Collect the block's lines (up to a blank line, a
            // `>` line, or a further `+`) and splice them into the quote body
            // behind a blank-line separator, so they parse as their own block
            // instead of folding into the preceding quoted paragraph.
            if (rtrim($currentLine) === '+') {
                $i++; // consume the `+` marker
                /** @var array<string> $attached */
                $attached = [];
                while ($i < $count) {
                    $line = $lines[$i];
                    if (IndentationHelper::isBlankLine($line)) {
                        break;
                    }
                    if ($this->blockQuoteLineContent($line) !== null) {
                        break; // a `>` line resumes the quote normally
                    }
                    if (rtrim($line) === '+') {
                        break; // a further `+` starts the next attached block
                    }
                    $attached[] = $line;
                    $i++;
                }
                if ($attached !== []) {
                    // $innerLines always holds the quote's first content line, so
                    // a leading blank separates the attached block from it.
                    $innerLines[] = '';
                    foreach ($attached as $attachedLine) {
                        $innerLines[] = $attachedLine;
                    }
                    $innerLines[] = '';
                    // The attached block closed any open paragraph: a following
                    // unmarked line no longer lazily continues the quote.
                    $lazyState['paragraphOpen'] = false;
                }

                continue;
            }

            // Continue with "> " prefix (space required per spec)
            $content = $this->blockQuoteLineContent($currentLine);
            if ($content !== null) {
                $innerLines[] = $content;
                $this->trackBlockQuoteLazyState($content, $lazyState);
                $i++;
            } elseif ($lazyState['paragraphOpen'] && !$this->endsBlockQuote($currentLine, $lazyState['paragraphTextOpen'])) {
                // Lazy continuation only extends an OPEN paragraph (djot rule).
                // A non-">" line inside an open code fence/comment, or after a
                // block that left no open paragraph (a just-opened div, a closed
                // fence), terminates the quote instead of being swallowed. A LIST
                // marker (bullet OR ordered) FOLDS into an open quoted PARAGRAPH
                // as literal text -- mirroring the top-level rule where a list
                // marker does not interrupt an open paragraph. But it only folds
                // when an open plain paragraph precedes it: after a heading,
                // table, or other closed block there is no paragraph to fold
                // into, so the list marker ENDS the quote and starts a sibling
                // list (endsBlockQuote() handles this via paragraphTextOpen).
                $innerLines[] = $currentLine;
                $this->trackBlockQuoteLazyState($currentLine, $lazyState);
                $i++;
            } else {
                break;
            }
        }

        $this->parseBlocks($blockQuote, $innerLines, 0);

        // Apply the saved attributes to the blockquote
        if ($quoteAttributes !== []) {
            $blockQuote->setAttributes($quoteAttributes);
        }
        $parent->appendChild($blockQuote);

        return $i - $start;
    }

    /**
     * Track verbatim/paragraph state across a blockquote's collected inner lines.
     *
     * A non-">" line lazily continues a blockquote only when an open paragraph is
     * available to extend (the djot/CommonMark lazy-continuation rule). Inside an
     * open code fence or fenced comment, or after a structural line that leaves no
     * open paragraph (a just-opened div, a closed fence), such a line must instead
     * terminate the quote - otherwise it is wrongly swallowed into the fence/div.
     *
     * The separate `paragraphTextOpen` flag is narrower than `paragraphOpen`:
     * it is true only after a plain PARAGRAPH-text line, and false after a
     * heading, table, thematic break, fence, comment, div, or blank line. It
     * decides whether a lazy list marker folds (open paragraph above) or ends
     * the quote (no open paragraph), mirroring the top-level rule that a list
     * marker folds into a paragraph but never into a heading.
     *
     * @param string $content Inner content line (after the "> " marker is stripped).
     * @param array{inFence:bool,fenceChar:string,fenceLength:int,inComment:bool,commentLength:int,paragraphOpen:bool,paragraphTextOpen:bool} $state
     *     Running state, mutated in place.
     */
    private function trackBlockQuoteLazyState(string $content, array &$state): void
    {
        if ($state['inComment']) {
            if ($this->fencedBlockParser->isFencedCommentCloser($content, $state['commentLength'])) {
                $state['inComment'] = false;
            }
            $state['paragraphOpen'] = false;
            $state['paragraphTextOpen'] = false;

            return;
        }

        if ($state['inFence']) {
            if ($this->fencedBlockParser->isCodeFenceCloser($content, $state['fenceChar'], $state['fenceLength'])) {
                $state['inFence'] = false;
            }
            $state['paragraphOpen'] = false;
            $state['paragraphTextOpen'] = false;

            return;
        }

        if (IndentationHelper::isBlankLine($content)) {
            $state['paragraphOpen'] = false;
            $state['paragraphTextOpen'] = false;

            return;
        }

        // This only tracks LAZY-CONTINUATION state (which non-">" lines extend the
        // quote), not how the collected content is block-parsed -- §10 paragraph
        // interruption (with its fence/div closer lookahead) is applied later by
        // parseBlocks. A fence/comment/div opener begins a fence/comment/div state
        // only when no paragraph is open (the opener is the first content, or
        // follows a blank line); a marker mid-paragraph leaves the paragraph open
        // so a following unquoted line still lazily continues it.
        if (!$state['paragraphOpen']) {
            $fenceInfo = $this->fencedBlockParser->parseCodeFenceOpener($content);
            if ($fenceInfo !== null) {
                $state['inFence'] = true;
                $state['fenceChar'] = $fenceInfo['char'];
                $state['fenceLength'] = $fenceInfo['length'];
                $state['paragraphOpen'] = false;
                $state['paragraphTextOpen'] = false;

                return;
            }

            $commentInfo = $this->fencedBlockParser->parseFencedCommentOpener($content);
            if ($commentInfo !== null) {
                $state['inComment'] = true;
                $state['commentLength'] = $commentInfo['length'];
                $state['paragraphOpen'] = false;
                $state['paragraphTextOpen'] = false;

                return;
            }

            if ($this->fencedBlockParser->parseDivFenceOpener($content) !== null) {
                // Div opener/closer line is structural; it opens no paragraph itself.
                $state['paragraphOpen'] = false;
                $state['paragraphTextOpen'] = false;

                return;
            }
        }

        // Any other non-blank line is paragraph-ish content (plain text, an open
        // paragraph's continuation, or a block that opens with text on the same line:
        // list item, heading, nested quote) - all leave an open paragraph a lazy line
        // may continue.
        $state['paragraphOpen'] = true;

        // A list marker folds only into an open PLAIN paragraph. A heading,
        // table row, or thematic break is a closed block that leaves no
        // paragraph for a following list marker to fold into, so it clears
        // paragraphTextOpen; plain text (including an open paragraph this line
        // continues) sets it. Mirrors the top-level rule: `text\n- item` folds,
        // `# h\n- item` is a heading plus a sibling list.
        $trimmed = ltrim($content);
        $isHeading = preg_match('/^#{1,6} .*\S/', $trimmed) === 1;
        $isThematicBreak = preg_match('/^([-*_])(?:[ \t]*\1){2,}[ \t]*$/', $trimmed) === 1;
        $isTableRow = $this->tableParser->isTableRow($trimmed);
        $state['paragraphTextOpen'] = !$isHeading && !$isThematicBreak && !$isTableRow;
    }

    /**
     * @param \Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseList(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Try to match list item marker. The marker is matched on the trimmed
        // line so an indented bullet/ordered marker still opens a list (Rule B:
        // a list opens at any indentation, not only at column 0); the leading
        // indentation becomes the list's base column (getLeadingColumns below).
        $listInfo = $this->listParser->parseListItemMarker(ltrim($line));
        if ($listInfo === null) {
            return null;
        }

        // Disambiguate roman vs alphabetical for single-letter markers
        // by looking at subsequent items
        if (!empty($listInfo['ambiguous'])) {
            $listInfo = $this->listParser->disambiguateListStyle($listInfo, $lines, $start);
        }

        // Get the base indentation of this list
        $baseIndent = IndentationHelper::getLeadingColumns($line);

        /** @var string $listType */
        $listType = $listInfo['type'];
        /** @var int $listStart */
        $listStart = $listInfo['start'] ?? 1;
        /** @var string|null $listMarker */
        $listMarker = $listInfo['marker'] ?? null;
        /** @var string|null $listStyle */
        $listStyle = $listInfo['style'] ?? null;

        $list = new ListBlock(
            $listType,
            $listStart,
            true, // Start as tight
            $listMarker,
            $listStyle,
        );

        // Save and clear pending attributes - they apply to the list, not inner content
        $listAttributes = $this->pendingAttributes;
        $this->pendingAttributes = [];

        $i = $start;
        $count = count($lines);
        $lastItemHadBlankAfter = false;
        $firstItem = true; // Track first item to use listInfo directly

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Skip blank lines, track them for tight/loose determination
            if (IndentationHelper::isBlankLine($currentLine)) {
                $lastItemHadBlankAfter = true;
                $i++;

                continue;
            }

            // Get indentation of current line
            $currentIndent = IndentationHelper::getLeadingColumns($currentLine);

            // If line is less indented than base, we're done with this list
            if ($currentIndent < $baseIndent) {
                break;
            }

            // List-continuation marker (Carve): a lone `+` at the marker column
            // attaches the FOLLOWING flush-left block to the current item, with
            // no blank line, keeping the list tight. A bare `+` is never a bullet
            // (a bullet needs `+ ` + content), so this does not collide with
            // `+`-bulleted lists; lets you attach a code block, table or quote to
            // an item without indenting its body.
            if ($currentIndent === $baseIndent && trim($currentLine) === '+') {
                $lastItem = $this->listParser->getLastListItem($list);
                if ($lastItem !== null) {
                    $i++; // consume the `+` marker line
                    /** @var array<string> $attached */
                    $attached = [];
                    while ($i < $count) {
                        $line = $lines[$i];
                        if (IndentationHelper::isBlankLine($line)) {
                            break;
                        }
                        $lineIndent = IndentationHelper::getLeadingColumns($line);
                        if ($lineIndent < $baseIndent) {
                            break;
                        }
                        $trimmed = ltrim($line);
                        if (
                            $lineIndent === $baseIndent
                            && ($this->listParser->parseListItemMarker($trimmed) !== null || $trimmed === '+')
                        ) {
                            break;
                        }
                        $attached[] = IndentationHelper::stripLeadingColumns($line, $baseIndent);
                        $i++;
                    }
                    if ($attached !== []) {
                        $this->parseBlocks($lastItem, $attached, 0);
                    }
                    // The continuation attaches content but does not loosen the list.
                    $lastItemHadBlankAfter = false;

                    continue;
                }
            }

            // Indented content belonging to the previous item. Carve
            // enters this for an indented list marker even with no
            // preceding blank line (tight nesting); other indented
            // content still requires the blank line (loose nesting).
            $indentedListMarker = $currentIndent > $baseIndent
                && $this->listParser->parseListItemMarker(ltrim($currentLine)) !== null;
            if (($lastItemHadBlankAfter || $indentedListMarker) && $currentIndent > $baseIndent) {
                // Content after blank line with indentation belongs to previous item
                $lastItem = $this->listParser->getLastListItem($list);
                if ($lastItem !== null) {
                    // Compact list blocks (Carve): a blank line before indented
                    // content does not loosen the list when that content OPENS A
                    // BLOCK (sub-list, block quote, fenced code, fenced div,
                    // heading, table). Only a genuine second prose paragraph makes
                    // the list loose. Block recognition and the uniformity
                    // principle are unchanged -- only tight/loose RENDERING moves.
                    $trimmedCurrent = ltrim($currentLine);
                    $firstContentOpensBlock =
                        $this->listParser->parseListItemMarker($trimmedCurrent) !== null
                        || $this->isBlockElementStart($trimmedCurrent);
                    if (!$firstContentOpensBlock) {
                        // Indented plain text after a blank line = a second
                        // paragraph in the item => loose list.
                        $list->setTight(false);
                    }

                    // Collect all indented content at this new level
                    $subLines = [];
                    $subIndent = $currentIndent;
                    // Track the maximum content indent we've seen (for detecting drop-back to marker level)
                    $maxContentIndent = $currentIndent;
                    $sawBlankLine = false;
                    $brokeForParentContent = false;
                    // Trailing-block state over the collected nested lines, so a
                    // base-level lazy line folds only when the nested content
                    // ends in an OPEN paragraph (family-D rule). After a CLOSED
                    // block (fenced code, table, div) the dedented line ends the
                    // item instead of being absorbed.
                    $subTrailingState = self::INITIAL_TRAILING_BLOCK_STATE;
                    while ($i < $count) {
                        $subLine = $lines[$i];
                        if (IndentationHelper::isBlankLine($subLine)) {
                            $subLines[] = '';
                            $sawBlankLine = true;
                            $i++;

                            continue;
                        }
                        $lineIndent = IndentationHelper::getLeadingColumns($subLine);

                        // If we've seen content at a higher indent level (actual nested content),
                        // and now we're back at the marker level (subIndent) after a blank line,
                        // this content belongs to the parent level - break to let parent handle it
                        if ($lineIndent === $subIndent && $maxContentIndent > $subIndent && $sawBlankLine) {
                            // Set flags so parent loop handles this as continuation content
                            $lastItemHadBlankAfter = true;
                            $brokeForParentContent = true;

                            break;
                        }

                        // Check if line has at least the subIndent level
                        if ($lineIndent >= $subIndent) {
                            // Track the highest content indent seen
                            if ($lineIndent > $maxContentIndent) {
                                $maxContentIndent = $lineIndent;
                            }
                            // Remove subIndent worth of indentation (handling tabs)
                            $stripped = IndentationHelper::stripLeadingColumns($subLine, $subIndent);
                            $subLines[] = $stripped;
                            $subTrailingState = $this->advanceTrailingBlockState($subTrailingState, $stripped);
                            $sawBlankLine = false;
                            $i++;
                        } elseif ($lineIndent === $baseIndent) {
                            // Line is at base indent - check if it starts a new block or list item
                            $trimmedLine = ltrim($subLine);
                            $itemInfo = $this->listParser->parseListItemMarker($trimmedLine);
                            $sameStyle = !isset($listInfo['style']) || !isset($itemInfo['style']) || $itemInfo['style'] === $listInfo['style'];
                            if ($itemInfo !== null && $itemInfo['type'] === $listInfo['type'] && $itemInfo['marker'] === $listInfo['marker'] && $sameStyle) {
                                if ($sawBlankLine) {
                                    $lastItemHadBlankAfter = true;
                                    $brokeForParentContent = true;
                                }

                                break;
                            }
                            // After a blank line, content dropping back to base indent
                            // starts a new block outside the list - let parent handle it.
                            if ($sawBlankLine) {
                                $lastItemHadBlankAfter = true;
                                $brokeForParentContent = true;

                                break;
                            }
                            // Content at base indent that's not a matching list marker
                            // Check if it's a block element - if so, end list content collection
                            // Use isBlockElementStart() which detects blocks regardless of mode
                            if ($this->isBlockElementStart($trimmedLine) || $this->startsNewBlock($trimmedLine)) {
                                break;
                            }
                            // Otherwise it's lazy continuation at base level. It
                            // only folds into the nested content when that
                            // content ends in an OPEN paragraph; after a closed
                            // block (code/table/div) the dedented line ends the
                            // item (family-D rule, matching carve-js/carve-rs).
                            if (
                                !$subTrailingState['openParagraph']
                                && !$subTrailingState['inFence']
                                && !$subTrailingState['inDiv']
                            ) {
                                break;
                            }
                            $subLines[] = $trimmedLine;
                            $subTrailingState = $this->advanceTrailingBlockState($subTrailingState, $trimmedLine);
                            $sawBlankLine = false;
                            $i++;
                        } elseif ($lineIndent > $baseIndent) {
                            // Line is at intermediate indent (between base and nested content)
                            // Without a preceding blank, plain text here lazily
                            // continues the deepest paragraph in the nested parse.
                            // Strip all leading whitespace before forwarding it,
                            // matching CommonMark lazy continuation.
                            $trimmedLine = ltrim($subLine);
                            if (
                                !$sawBlankLine
                                && !$this->isBlockElementStart($trimmedLine)
                                && !$this->startsNewBlock($trimmedLine)
                            ) {
                                $subLines[] = $trimmedLine;
                                $subTrailingState = $this->advanceTrailingBlockState($subTrailingState, $trimmedLine);
                                $i++;

                                continue;
                            }

                            break;
                        } else {
                            // End of list
                            break;
                        }
                    }
                    // Remove trailing blank lines from subLines
                    $subLineCount = count($subLines);
                    while ($subLineCount > 0 && $subLines[$subLineCount - 1] === '') {
                        array_pop($subLines);
                        $subLineCount--;
                    }
                    // Parse nested content
                    if ($subLines !== []) {
                        $this->parseBlocks($lastItem, $subLines, 0);
                    }
                    // In djot, blank lines within nested content don't make the parent list loose
                    // The list is only loose if there's a blank line directly after item content
                    // (before nested content starts), which is already handled elsewhere
                    // Only reset if we didn't break to handle content at parent level
                    if (!$brokeForParentContent) {
                        $lastItemHadBlankAfter = false;
                    }

                    continue;
                }
            }

            // For first item, use the already-parsed listInfo (may have been disambiguated)
            // For subsequent items, parse fresh
            $trimmedLine = ltrim($currentLine);
            if ($firstItem) {
                $itemInfo = $listInfo;
                $firstItem = false;
            } else {
                // Only match items at the same indentation level
                if ($currentIndent !== $baseIndent) {
                    break;
                }
                $itemInfo = $this->listParser->parseListItemMarker($trimmedLine);

                // Check if this is a list item of the same type, marker, and style
                if ($itemInfo === null || !$this->listParser->itemMatchesList($listInfo, $itemInfo)) {
                    break;
                }
            }

            // If there was a blank line before this item, list is loose
            if ($lastItemHadBlankAfter) {
                $list->setTight(false);
            }

            /** @var string|null $taskMarker */
            $taskMarker = $itemInfo['taskMarker'] ?? null;
            $listItem = new ListItem($taskMarker);
            // Attributes from an abutting `{...}` block attach to the <li>.
            if (isset($itemInfo['attributes'])) {
                /** @var array<string, string> $markerAttributes */
                $markerAttributes = $itemInfo['attributes'];
                foreach ($markerAttributes as $key => $value) {
                    $listItem->setAttribute($key, $value);
                }
            }
            /** @var string $itemContent */
            $itemContent = $itemInfo['content'];

            // Collect item content lines (without blank line = tight continuation)
            /** @var array<string> $itemLines */
            $itemLines = [$itemContent];
            $i++;
            $lastItemHadBlankAfter = false;

            // Trailing-block tracker for CommonMark lazy continuation. Updated
            // incrementally for each collected line (advanceTrailingBlockState)
            // so the lazy-continuation gate below is O(1) per line instead of
            // rescanning all collected lines. `openParagraph` is false when the
            // trailing top-level block is a fenced code block or a table (no
            // open paragraph for a dedented line to fold into).
            $trailingState = self::INITIAL_TRAILING_BLOCK_STATE;
            $trailingState = $this->advanceTrailingBlockState($trailingState, $itemContent);

            // First-block item (Carve): `- +` opens an item whose body is the
            // flush-left block that follows, with no indentation. A lone `+` as
            // the sole item content is the continuation marker, not literal text
            // (`- + text` keeps `+ text` as literal content). This lets an item
            // start directly with a table, code block, quote or div at column 0.
            if (trim($itemContent) === '+') {
                /** @var array<string> $attached */
                $attached = [];
                while ($i < $count) {
                    $line = $lines[$i];
                    if (IndentationHelper::isBlankLine($line)) {
                        break;
                    }
                    $lineIndent = IndentationHelper::getLeadingColumns($line);
                    if ($lineIndent < $baseIndent) {
                        break;
                    }
                    $trimmed = ltrim($line);
                    if (
                        $lineIndent === $baseIndent
                        && ($this->listParser->parseListItemMarker($trimmed) !== null || $trimmed === '+')
                    ) {
                        break;
                    }
                    $attached[] = IndentationHelper::stripLeadingColumns($line, $baseIndent);
                    $i++;
                }
                if ($attached !== []) {
                    $this->parseBlocks($listItem, $attached, 0);
                }
                $list->appendChild($listItem);

                continue;
            }

            // Calculate content indent based on list type and marker width
            // For bullet lists (including task lists): use 2 (for "- ")
            // For ordered lists: use actual marker width (varies with number length)
            // Task list checkbox is considered part of content, not marker
            if ($listType === ListBlock::TYPE_ORDERED) {
                // Ordered list marker width = length of trimmed line - length of content
                // Examples: "1. " = 3, "10. " = 4, "(1) " = 4, "(10) " = 5
                $markerWidth = strlen($trimmedLine) - strlen($itemContent);
            } else {
                // Bullet and task lists use 2-char base marker ("- " or "* " or "+ ")
                $markerWidth = 2;
            }
            $contentIndent = $baseIndent + $markerWidth;

            // When the item's content BEGINS, on the marker line, with another
            // list marker (`- - A`, `* - A`, `1. - A`, ...), the lead is itself
            // a sub-list, not a paragraph. Carve then parses the lead together
            // with every following dedented line as ONE block stream so the
            // marker-line sub-list behaves exactly like a sub-list opened on a
            // *following* line: following same-indent markers MERGE into it as
            // siblings, and post-blank indented blocks are ABSORBED into its
            // items. This MATCHES reference djot.js (the djot/djot package
            // 0.3.2) and CommonMark, which both treat a marker-line sub-list as
            // a normal nested list. It corrects Carve's prior line-scoping
            // (which split the sub-list from following items and leaked later
            // indented blocks to the parent row) -- a bug inherited from
            // djot-php, whose marker-line handling deviates from reference djot
            // (a parallel fix is in flight on php-collective/djot-php). The
            // single combined stream reuses the normal nested-list/absorption
            // logic -- no separate path.
            $leadIsMarker = $this->listParser->parseListItemMarker($itemContent) !== null;
            if ($leadIsMarker) {
                $i = $this->collectMarkerLeadItem(
                    $lines,
                    $i,
                    $count,
                    $baseIndent,
                    $contentIndent,
                    $itemLines,
                );
                $this->parseBlocks($listItem, $itemLines, 0);
                $list->appendChild($listItem);

                // A blank line directly before the next sibling marker still
                // loosens the list; mirror the plain-item rule by remembering
                // any trailing blank consumed inside the combined stream.
                if ($i < $count && IndentationHelper::isBlankLine($lines[$i])) {
                    $lastItemHadBlankAfter = true;
                }

                continue;
            }

            while ($i < $count) {
                $nextLine = $lines[$i];

                if (IndentationHelper::isBlankLine($nextLine)) {
                    break;
                }

                $nextIndent = IndentationHelper::getLeadingColumns($nextLine);
                $nextTrimmed = ltrim($nextLine);

                // A MARKER (or block opener) dedented BELOW the list's base
                // column ends this list: the outer parser then starts a new
                // sibling list at the dedented column (Rule B -- a list opens at
                // ANY indentation, so distinct base columns are distinct lists),
                // or the outer block. Without this a dedented marker would lazily
                // fold into the current item (`  - a` / `  - b` / `- c` -> c
                // stuck on b). Plain text dedented below the base still lazily
                // continues the item (CommonMark lazy continuation), so only a
                // marker/block breaks here. Matches carve-js.
                if (
                    $nextIndent < $baseIndent
                    && (
                        $this->listParser->parseListItemMarker($nextTrimmed) !== null
                        || $this->isBlockElementStart($nextTrimmed)
                        || $this->startsNewBlock($nextTrimmed)
                    )
                ) {
                    break;
                }

                // Check if next line starts a new list item at same level (base indent)
                if ($nextIndent === $baseIndent) {
                    $nextInfo = $this->listParser->parseListItemMarker($nextTrimmed);
                    if ($nextInfo !== null) {
                        break;
                    }
                    // List-continuation marker: stop collecting lead text so the
                    // main loop's `+` handler attaches the following block to this
                    // item (instead of swallowing `+` as lazy continuation text).
                    if ($nextTrimmed === '+') {
                        break;
                    }
                    // Non-list content at base indent: a line that starts a block
                    // ends the list (lazy continuation only extends a paragraph).
                    // isBlockElementStart() detects blocks regardless of context;
                    // startsNewBlock() additionally covers paragraph interruption.
                    if ($this->isBlockElementStart($nextTrimmed) || $this->startsNewBlock($nextTrimmed)) {
                        break;
                    }
                }

                // Content at content indent or more is continuation.
                // Carve nests an indented list marker directly (no blank
                // line required): "- a\n  - b" makes "- b" a child list.
                // Break out so the outer loop collects it as nested content.
                if ($nextIndent >= $contentIndent) {
                    if ($this->listParser->parseListItemMarker($nextTrimmed) !== null) {
                        break;
                    }
                    // Properly indented continuation - include with original indentation relative to content
                    $contentLine = IndentationHelper::stripLeadingColumns($nextLine, $contentIndent);
                    $itemLines[] = $contentLine;
                    $trailingState = $this->advanceTrailingBlockState($trailingState, $contentLine);
                } else {
                    // Lazy continuation (not properly indented but not at base
                    // level either). CommonMark lazy continuation only extends
                    // an OPEN paragraph: if the item's trailing block is a
                    // CLOSED fenced code block or a table there is no open
                    // paragraph, so the dedented line ends the item and becomes
                    // a top-level block. An UNTERMINATED fence (inFence still
                    // open) is NOT a code block -- it is an inline-verbatim run
                    // that is part of the paragraph, so the dedented line folds
                    // in (matching the §10 closer-lookahead rule).
                    if (
                        !$trailingState['openParagraph']
                        && !$trailingState['inFence']
                        && !$trailingState['inDiv']
                    ) {
                        break;
                    }
                    $itemLines[] = $nextTrimmed;
                    $trailingState = $this->advanceTrailingBlockState($trailingState, $nextTrimmed);
                }
                $i++;
            }

            // For tight lists with continuation lines, check if content starts with
            // a block element. If so, parse as blocks; otherwise parse as plain text.
            // This prevents "-like" lines from being parsed as nested lists while
            // still allowing blockquotes, code blocks, etc. to be properly recognized.
            // Item content parses as blocks. Per grammar §10 only a list marker
            // interrupts nested content without a blank line (sublists are
            // collected above); a non-list block opener after lead text stays
            // paragraph text, so tryParseParagraph folds it into the lead
            // paragraph rather than splitting it into a separate block.
            $this->parseBlocks($listItem, $itemLines, 0);

            $list->appendChild($listItem);
        }

        // Apply the saved attributes to the list
        if ($listAttributes !== []) {
            $list->setAttributes($listAttributes);
        }
        $parent->appendChild($list);

        return $i - $start;
    }

    /**
     * Collect the body of a list item whose lead content (on the marker line)
     * is itself a list marker, as a SINGLE block stream.
     *
     * The lead marker line is already in $itemLines (dedented to column 0). This
     * appends every following line that belongs to the item -- nested content at
     * or past the content column, and internal blank lines -- dedented by
     * $contentIndent, so the combined stream parses through the normal
     * nested-list/absorption path (one persistent sub-list rather than a split
     * sub-list plus a leaked parent-row block). Collection stops at end of
     * input, a line dedented below the content column, or a blank line that is
     * NOT followed by further item-owned indented content.
     *
     * @param array<string> $lines All lines being parsed.
     * @param int $i Index of the first line AFTER the lead marker line.
     * @param int $count Total line count.
     * @param int $baseIndent The list's base column.
     * @param int $contentIndent The item's content column.
     * @param array<string> $itemLines Collected stream (lead marker line already present); appended in place.
     *
     * @return int The index of the first line NOT consumed.
     */
    protected function collectMarkerLeadItem(
        array $lines,
        int $i,
        int $count,
        int $baseIndent,
        int $contentIndent,
        array &$itemLines,
    ): int {
        while ($i < $count) {
            $nextLine = $lines[$i];

            if (IndentationHelper::isBlankLine($nextLine)) {
                // A blank only stays inside the item when item-owned indented
                // content (>= content column) follows it; otherwise it ends the
                // item (the next non-blank starts a sibling or an outer block).
                $look = $i + 1;
                while ($look < $count && IndentationHelper::isBlankLine($lines[$look])) {
                    $look++;
                }
                if ($look >= $count || IndentationHelper::getLeadingColumns($lines[$look]) < $contentIndent) {
                    break;
                }
                $itemLines[] = '';
                $i++;

                continue;
            }

            // Content dedented below the content column ends the item: a sibling
            // marker or outer block at the base column, or anything further left,
            // is handled by the caller's loop. Unlike the plain-lead case there
            // is no lazy paragraph continuation here -- the lead is a sub-list,
            // not a paragraph, so a dedented line never folds in.
            if (IndentationHelper::getLeadingColumns($nextLine) < $contentIndent) {
                break;
            }

            $itemLines[] = IndentationHelper::stripLeadingColumns($nextLine, $contentIndent);
            $i++;
        }

        // Drop trailing blank lines from the collected stream.
        $lineCount = count($itemLines);
        while ($lineCount > 0 && $itemLines[$lineCount - 1] === '') {
            array_pop($itemLines);
            $lineCount--;
        }

        return $i;
    }

    /**
     * Carve definition list (§4.5): `:: term` (exactly two colons, not a
     * `:::` div) lines, then `: definition` (colon + two spaces) lines.
     * Deeper-indented lines continue a definition; a single blank line may
     * separate entries. Renders to <dl> of <dt> then <dd>.
     *
     * @param \Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseDefinitionList(Node $parent, array $lines, int $start): ?int
    {
        if (!preg_match('/^::(?!:)\s+(.+)$/', $lines[$start])) {
            return null;
        }

        $dl = new DefinitionList();
        $this->applyPendingAttributes($dl);
        $i = $start;
        $count = count($lines);

        while ($i < $count && preg_match('/^::(?!:)\s+(.+)$/', $lines[$i])) {
            // An entry: one or more terms, then one or more definitions.
            while ($i < $count && preg_match('/^::(?!:)\s+(.+)$/', $lines[$i], $m)) {
                $term = new DefinitionTerm();
                $this->inlineParser->parse($term, trim($m[1]), $i);
                $dl->appendChild($term);
                $i++;
            }
            while ($i < $count && preg_match('/^:\s\s+(.+)$/', $lines[$i], $m)) {
                $body = [trim($m[1])];
                $i++;
                // Deeper-indented (>= 3 spaces) non-blank lines continue the def.
                while ($i < $count) {
                    $contLine = $lines[$i];
                    $contLen = strlen($contLine);
                    $indent = $contLen - strlen(ltrim($contLine, ' '));
                    if (trim($contLine) === '' || $indent < 3) {
                        break;
                    }
                    $body[] = ltrim($contLine);
                    $i++;
                }
                $dd = new DefinitionDescription();
                $this->parseBlocks($dd, $body, 0);
                $dl->appendChild($dd);
            }
            // Allow a single blank line before the next entry's `:: term`.
            if ($i < $count && trim($lines[$i]) === '') {
                $look = $i;
                while ($look < $count && trim($lines[$look]) === '') {
                    $look++;
                }
                if ($look < $count && preg_match('/^::(?!:)\s+/', $lines[$look])) {
                    $i = $look;

                    continue;
                }
            }

            break;
        }

        $parent->appendChild($dl);

        return $i - $start;
    }

    /**
     * Split lines into blocks separated by blank lines
     *
     * @param array<string> $lines
     *
     * @return array<array<string>>
     */
    protected function splitByBlankLines(array $lines): array
    {
        $blocks = [];
        $current = [];

        // Skip leading blank lines using index (avoid O(n) array_shift)
        $start = 0;
        $count = count($lines);
        while ($start < $count && IndentationHelper::isBlankLine($lines[$start])) {
            $start++;
        }

        for ($i = $start; $i < $count; $i++) {
            $line = $lines[$i];
            if (IndentationHelper::isBlankLine($line)) {
                if ($current !== []) {
                    $blocks[] = $current;
                    $current = [];
                }
            } else {
                $current[] = $line;
            }
        }

        // Don't forget the last block
        if ($current !== []) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * Try to parse a line block (preserves author line layout).
     *
     * @param \Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseLineBlock(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        $divInfo = $this->fencedBlockParser->parseDivFenceOpener($line);
        if ($divInfo === null) {
            return null;
        }

        // A bare pipe `|` is the line-block type token (carve spec, jgm/djot#29);
        // `::: |` is the only line-block opener.
        if (
            preg_match(
                '/^\|(?:\s*(?<attrs>\{.*\}))?\s*$/s',
                $divInfo['className'],
                $openerMatches,
                PREG_UNMATCHED_AS_NULL,
            ) !== 1
        ) {
            return null;
        }

        $i = $start;
        $count = count($lines);
        $contentLines = [];
        $closed = false;

        $i++;
        while ($i < $count) {
            $currentLine = $lines[$i];

            if ($this->fencedBlockParser->isDivFenceCloser($currentLine, $divInfo['length'])) {
                $i++;
                $closed = true;

                break;
            }

            $contentLines[] = $currentLine;
            $i++;
        }

        if (!$closed) {
            return null;
        }

        $lineBlock = new LineBlock();
        $this->applyPendingAttributes($lineBlock);
        if (($openerMatches['attrs'] ?? null) !== null) {
            AttributeParser::applyToNode($lineBlock, substr($openerMatches['attrs'], 1, -1));
        }

        $stanza = [];
        $lineNumber = $start + 1;
        foreach ($contentLines as $contentLine) {
            if (IndentationHelper::isBlankLine($contentLine)) {
                $this->appendLineBlockStanza($lineBlock, $stanza);
                $stanza = [];
            } else {
                $stanza[] = [$contentLine, $lineNumber];
            }

            $lineNumber++;
        }
        $this->appendLineBlockStanza($lineBlock, $stanza);

        $parent->appendChild($lineBlock);

        return $i - $start;
    }

    /**
     * @param \Carve\Node\Block\LineBlock $lineBlock
     * @param list<array{0: string, 1: int}> $lines
     */
    protected function appendLineBlockStanza(LineBlock $lineBlock, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $paragraph = new Paragraph();
        $lastIndex = count($lines) - 1;

        foreach ($lines as $index => [$line, $lineNumber]) {
            $this->appendLineBlockLine($paragraph, $line, $lineNumber);
            if ($index < $lastIndex) {
                $paragraph->appendChild(new HardBreak());
            }
        }

        $lineBlock->appendChild($paragraph);
    }

    /**
     * Append a single line-block line, preserving significant whitespace.
     *
     * Leading indentation is always kept (even a single column). An inner or
     * trailing run of TWO OR MORE columns is a medial gap (inline alignment,
     * e.g. the caesura of Old English verse) and is kept too; a lone inner space
     * stays an ordinary, collapsible space so a long line can still wrap between
     * words. Preserved columns are emitted via the internal non-breaking-space
     * placeholder (U+E000), which each renderer converts (HTML &nbsp;, Markdown
     * U+00A0, plain space) and which never collides with a literal U+00A0 in the
     * author's text. Tabs expand to four-column stops.
     */
    protected function appendLineBlockLine(Paragraph $paragraph, string $line, int $lineNo): void
    {
        $length = strlen($line);
        $offset = 0;
        $column = 0;
        $text = '';
        $seenContent = false;

        while ($offset < $length) {
            $char = $line[$offset];
            if ($char !== ' ' && $char !== "\t") {
                $text .= $char;
                $seenContent = true;
                $column++;
                $offset++;

                continue;
            }

            $width = 0;
            while ($offset < $length && ($line[$offset] === ' ' || $line[$offset] === "\t")) {
                if ($line[$offset] === "\t") {
                    $width += 4 - (($column + $width) % 4);
                } else {
                    $width++;
                }
                $offset++;
            }
            $column += $width;

            if (!$seenContent || $width >= 2) {
                if ($text !== '') {
                    $this->inlineParser->parse($paragraph, $text, $lineNo);
                    $text = '';
                }
                $paragraph->appendChild(new Text(str_repeat("\u{E000}", $width)));

                continue;
            }

            $text .= ' ';
        }

        if ($text !== '') {
            $this->inlineParser->parse($paragraph, $text, $lineNo);
        }
    }

    /**
     * Parse a Carve table cell's tight alignment/header marker (written
     * tight against the pipe): optional `=` (header) then optional one of
     * `< > ~` (left/right/center). Returns the flags plus the content with
     * the marker stripped. Spaced markers (`| ^ |`, `| < |`) are span
     * markers, not alignment — their leading space means index 0 is not a
     * marker char, so they are left untouched here.
     *
     * @return array{header: bool, align: string|null, content: string}
     */
    protected function parseTableCellMarker(string $raw): array
    {
        $header = false;
        $rest = $raw;
        // `==x==` is a highlight cell, so `=` must not be followed by `=`.
        if (isset($rest[0]) && $rest[0] === '=' && ($rest[1] ?? '') !== '=') {
            $header = true;
            $rest = substr($rest, 1);
        }
        $align = match ($rest[0] ?? '') {
            '>' => TableCell::ALIGN_RIGHT,
            '<' => TableCell::ALIGN_LEFT,
            '~' => TableCell::ALIGN_CENTER,
            default => null,
        };
        if ($align !== null) {
            $rest = substr($rest, 1);
        }

        return ['header' => $header, 'align' => $align, 'content' => $rest];
    }

    /**
     * @param \Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseTable(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];
        $count = count($lines);

        // Use TableParser to check if this is a valid table row
        if (!$this->tableParser->isTableRow($line)) {
            // Check if it's a potential table row with unclosed code span
            // that might be closed by continuation rows
            if (!$this->tableParser->isPotentialTableRowWithUnclosedCodeSpan($line)) {
                return null;
            }

            // Look ahead for continuation rows that might close the code span
            if (!$this->canCloseCodeSpanWithContinuations($lines, $start, $count)) {
                return null;
            }
        }

        $table = new Table();
        $i = $start;
        $alignments = [];
        // Per-column alignment from Carve header markers (|=>, |=~, |=<),
        // keyed by column position; propagates to the column's body cells.
        $columnAligns = [];
        $headerFound = false;
        // Per-column "open" origin cell, carried down across rows so a `^`
        // marker extends it in O(1) instead of rescanning all prior rows.
        $columnOrigin = [];

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Strip row attributes for validation (|...|{.class} → |...|)
            $lineWithoutRowAttrs = $this->tableParser->stripRowAttributes($currentLine);

            // Trailing whitespace after the closing pipe is insignificant
            // (parity with carve-js / carve-rs).
            if ($lineWithoutRowAttrs === '||' || !preg_match('/^\|.*\|[ \t]*$/', $lineWithoutRowAttrs)) {
                break;
            }

            // A GFM header separator is recognized ONLY as the table's second row
            // (exactly one row precedes it and no separator was seen yet): it makes
            // that first row the header. A delimiter line anywhere else -- leading,
            // or after the header/body -- is an ordinary data row. This matches
            // carve-js / carve-rs (the separator is the second row, period).
            if (
                $this->tableParser->isSeparatorRow($lineWithoutRowAttrs)
                && count($table->getChildren()) === 1
                && !$headerFound
            ) {
                $alignments = $this->tableParser->parseTableAlignments($lineWithoutRowAttrs);
                $headerFound = true;

                // Store separator widths for round-trip preservation
                $separatorWidths = $this->tableParser->parseSeparatorWidths($lineWithoutRowAttrs);
                $table->setSeparatorWidths($separatorWidths);

                // Mark previous row as header and apply alignments to it
                $children = $table->getChildren();
                if ($children !== []) {
                    $lastRow = $children[count($children) - 1];
                    if ($lastRow instanceof TableRow) {
                        // Recreate as header row with alignments
                        $headerRow = new TableRow(true);
                        // Preserve row attributes from original row
                        $headerRow->setAttributes($lastRow->getAttributes());
                        $cellIndex = 0;
                        $colPos = 0;
                        foreach ($lastRow->getChildren() as $cell) {
                            if ($cell instanceof TableCell) {
                                $alignment = $alignments[$cellIndex] ?? TableCell::ALIGN_DEFAULT;
                                $colspan = $cell->getColspan();
                                // Preserve rowspan and colspan from original cell
                                $headerCell = new TableCell(
                                    true,
                                    $alignment,
                                    $cell->getRowspan(),
                                    $colspan,
                                );
                                // Preserve cell attributes from original cell
                                $headerCell->setAttributes($cell->getAttributes());
                                foreach ($cell->getChildren() as $child) {
                                    $headerCell->appendChild($child);
                                }
                                $headerRow->appendChild($headerCell);
                                // The promoted header cell replaces the original, so
                                // repoint the rowspan origin to the NEW cell (else a
                                // later `^` extends the detached old cell and the
                                // header rowspan is lost). Seed only the cell's START
                                // column -- a colspan does not claim the columns it
                                // merely covers, so a `^` under a covered column has
                                // no origin and degrades to an empty cell (matching
                                // the body-row grid walk and carve-js / carve-rs).
                                $columnOrigin[$colPos] = $headerCell;
                                $colPos += $colspan;
                                $cellIndex++;
                            }
                        }
                        // Replace last row
                        $table->replaceChild(count($children) - 1, $headerRow);
                    }
                }
                $i++;

                continue;
            }

            // Extract row attributes (|...|{.class})
            $rowAttributes = $this->tableParser->extractRowAttributes($currentLine);

            // Parse cells with their attributes
            $cellsWithAttrs = $this->tableParser->parseTableCellsWithAttributes($currentLine);

            // Store cell contents and attributes for potential merging
            $mergedCells = array_map(fn ($c) => $c['content'], $cellsWithAttrs);
            $cellAttributes = array_map(fn ($c) => $c['attributes'], $cellsWithAttrs);
            $baseLineForRow = $i;

            $i++;

            // Check for continuation rows (lines starting with +)
            while ($i < $count && $this->tableParser->isContinuationRow($lines[$i])) {
                $continuationCells = $this->tableParser->parseContinuationCells($lines[$i]);
                $mergedCells = $this->tableParser->mergeCellContents($mergedCells, $continuationCells);
                $i++;
            }

            // Rebuild cellsWithAttrs with merged content
            $mergedCellsWithAttrs = [];
            foreach ($mergedCells as $idx => $content) {
                $mergedCellsWithAttrs[] = [
                    'content' => $content,
                    'attributes' => $cellAttributes[$idx] ?? '',
                ];
            }

            // Resolve `<`/`^` span markers into the row's output cells with the
            // same single grid walk the carve-js renderer uses (see resolveRowSpans).
            $resolved = $this->resolveRowSpans($mergedCellsWithAttrs, $columnOrigin);
            $processedCells = $resolved['cells'];
            $consumedRowspanColumns = $resolved['consumedRowspanColumns'];

            // Carve header row: every cell is "=" prefixed (|= Header |).
            // No separator row is used. "==x==" stays a normal cell
            // (highlight), so "=" must not be followed by another "=".
            $isHeaderRow = $processedCells !== [];
            foreach ($processedCells as $cellData) {
                $content = $cellData['content'];
                // An empty span cell (a `<`/`^` that became its own slot) and a
                // cell carrying an attribute block are never `|=` header cells, so
                // they never make the row a Carve all-header row (carve-js parity).
                if (
                    $cellData['isEmpty']
                    || $cellData['attributes'] !== ''
                    || preg_match('/^=([^=]|$)/', $content) !== 1
                ) {
                    $isHeaderRow = false;

                    break;
                }
            }

            // Parse regular row
            $row = new TableRow($isHeaderRow);
            if ($rowAttributes) {
                $row->setAttributes($rowAttributes);
            }

            // Build the row's cells. Spans are already resolved in the grid above,
            // so every entry in $processedCells emits exactly one cell; its
            // gridColumn keys per-column alignment, matching the carve-js renderer.
            /** @var array<array{cell: \Carve\Node\Block\TableCell, colPosition: int}> $rowCellData */
            $rowCellData = [];
            foreach ($processedCells as $cellData) {
                $colspan = $cellData['colspan'];
                $col = $cellData['gridColumn'];

                if ($cellData['isEmpty']) {
                    // A `<`/`^` marker that became an empty cell of its own (left
                    // edge, or a degenerate `^` with no cell above). It occupies
                    // its grid position and is never dropped. It still takes the
                    // column's alignment -- the Carve header marker first, then the
                    // GFM separator alignment -- so an empty span cell lines up with
                    // the real cells in its column (carve-js parity).
                    $alignment = $columnAligns[$col]
                        ?? $alignments[$col]
                        ?? TableCell::ALIGN_DEFAULT;
                    $cell = new TableCell($isHeaderRow, $alignment, 1, $colspan);
                    $row->appendChild($cell);
                    $rowCellData[] = ['cell' => $cell, 'colPosition' => $col];

                    continue;
                }

                // Parse the tight alignment/header marker. A header row fixes
                // per-column alignment; a cell's own marker overrides it; a djot
                // separator row is the final fallback. A cell carrying a `{...}`
                // attribute block has no tight marker -- its content is literal
                // (so `{.x} <` keeps the `<`).
                $marker = $cellData['attributes'] !== ''
                    ? ['align' => null, 'header' => false, 'content' => $cellData['content']]
                    : $this->parseTableCellMarker($cellData['content']);
                if ($isHeaderRow && $marker['align'] !== null) {
                    $columnAligns[$col] = $marker['align'];
                }
                $alignment = $marker['align']
                    ?? $columnAligns[$col]
                    ?? $alignments[$col]
                    ?? TableCell::ALIGN_DEFAULT;
                // A cell carries its own `=` marker even in a body row, so a
                // `|=` cell in a data row becomes a row header (<th> inside
                // <tbody>). The row stays a body row; only the cell is a header.
                $cell = new TableCell($isHeaderRow || $marker['header'], $alignment, 1, $colspan);
                if ($cellData['attributes'] !== '') {
                    // Apply in source order (matching inline attributes and
                    // carve-js), not via setAttributes() which reorders.
                    AttributeParser::applyToNode($cell, $cellData['attributes']);
                }
                $trimmedContent = trim($marker['content']);
                if ($trimmedContent !== '' && $this->isPlainText($trimmedContent)) {
                    $cell->appendChild(new Text($trimmedContent));
                } else {
                    $this->inlineParser->parse($cell, $trimmedContent, $baseLineForRow);
                }
                $row->appendChild($cell);
                $rowCellData[] = ['cell' => $cell, 'colPosition' => $col];
            }

            // Resolve rowspan markers: each consumed `^` extends the cell open in
            // its column from a row above. Multiple `^` against one origin extend
            // it only once per row. The grid pass already flagged which columns
            // hold a consumed `^` ($consumedRowspanColumns).
            $extendedCells = [];
            foreach ($consumedRowspanColumns as $col) {
                $origin = $columnOrigin[$col] ?? null;
                if ($origin instanceof TableCell) {
                    $originId = spl_object_id($origin);
                    if (!isset($extendedCells[$originId])) {
                        $origin->setRowspan($origin->getRowspan() + 1);
                        $extendedCells[$originId] = true;
                    }
                }
            }

            // Carry each emitted cell down as the open origin for its grid column.
            // Only the cell's own start column is seeded (matching the carve-js
            // renderer, where a colspan does not claim the columns it merely
            // covers); a column consumed by a `^` keeps the origin above it.
            foreach ($rowCellData as $cellInfo) {
                $columnOrigin[$cellInfo['colPosition']] = $cellInfo['cell'];
            }

            $table->appendChild($row);
        }

        // A separator-only table is valid (creates empty table)
        // Only return null if we didn't parse anything at all
        if (count($table->getChildren()) === 0 && !$headerFound) {
            return null;
        }

        $this->applyPendingAttributes($table);
        $parent->appendChild($table);

        // Caption parsing is now handled by tryParseCaption

        return $i - $start;
    }

    /**
     * Resolve a table row's `<`/`^` span markers into output cells using the
     * same single LEFT-TO-RIGHT grid walk the carve-js renderer uses (carve spec
     * section 96).
     *
     * At this stage no cell has been collapsed yet, so each source cell occupies
     * exactly one grid column and its index IS its grid column. For each column:
     *  - a `<` (colspan marker) grows the nearest NON-SKIPPED cell to its left,
     *    scanning PAST columns already consumed by another span; the `<`'s own
     *    column is then skipped. At the very left edge (no cell to the left) the
     *    `<` becomes an empty cell, which a following `<` can in turn grow.
     *  - a `^` (rowspan marker) whose column has an open origin from a row above
     *    is consumed: its column is skipped and recorded so the rowspan pass can
     *    extend that origin. With no cell above it is a degenerate marker and
     *    becomes an empty cell of its own.
     *  - any other cell is a normal content cell.
     * A skipped column emits no cell; its slot is covered by the span that
     * consumed it. Surviving cells remember their grid column so rowspan origins
     * and per-column alignment stay keyed by the true column even when a colspan
     * jumps a consumed `^`.
     *
     * @param array<int, array{content: string, attributes: string}> $mergedCellsWithAttrs
     * @param array<int, \Carve\Node\Block\TableCell> $columnOrigin Per-column open
     *   origin cell carried down from earlier rows.
     *
     * @return array{cells: array<array{content: string, attributes: string, colspan: int<1, max>, gridColumn: int, isEmpty: bool}>, consumedRowspanColumns: array<int>}
     */
    protected function resolveRowSpans(array $mergedCellsWithAttrs, array $columnOrigin): array
    {
        // Parallel per-column state (keyed by grid column = source index). Plain
        // scalar maps so the colspan++ / skip mutations stay simple for the
        // static analyzer.
        $count = count($mergedCellsWithAttrs);
        /** @var array<int, bool> $skip */
        $skip = array_fill(0, $count, false);
        /** @var array<int, bool> $empty */
        $empty = array_fill(0, $count, false);
        /** @var array<int, int> $colspan */
        $colspan = array_fill(0, $count, 1);
        $consumedRowspanColumns = [];

        foreach ($mergedCellsWithAttrs as $col => $cellData) {
            $isColspanMarker = $cellData['attributes'] === ''
                && $this->tableParser->isColspanMarker($cellData['content']);
            $isRowspanMarker = $cellData['attributes'] === ''
                && $this->tableParser->isRowspanMarker($cellData['content']);

            // A cell carrying attributes is never a bare span marker, so its
            // `<`/`^` content is literal (carve-js / carve-rs parity).
            if ($isColspanMarker && $col > 0) {
                // Scan left, skipping columns already consumed by a span.
                $left = $col - 1;
                while ($left >= 0 && ($skip[$left] ?? false)) {
                    $left--;
                }
                if ($left >= 0) {
                    // Merge into the available cell to the left. Its content (or
                    // empty-cell slot) grows by one column; this column is covered,
                    // so it emits nothing.
                    $colspan[$left] = ($colspan[$left] ?? 1) + 1;
                    $skip[$col] = true;

                    continue;
                }
                // Ran off the left edge: the `<` becomes an empty cell of its own
                // (a later `<` can still grow it).
                $empty[$col] = true;

                continue;
            }

            if ($isRowspanMarker && isset($columnOrigin[$col])) {
                // Consumed `^`: it extends the cell open above (resolved in the
                // rowspan pass) and its column is covered.
                $skip[$col] = true;
                $consumedRowspanColumns[] = $col;

                continue;
            }

            if ($isColspanMarker || $isRowspanMarker) {
                // A leading `<` (col 0), or a degenerate `^` with no cell above,
                // becomes an empty cell occupying its own grid position (never
                // dropped -- spec "Table span marker in first column").
                $empty[$col] = true;
            }
        }

        // Flatten: skipped columns produce no cell.
        $cells = [];
        foreach ($mergedCellsWithAttrs as $col => $cellData) {
            if ($skip[$col] ?? false) {
                continue;
            }
            $isEmpty = $empty[$col] ?? false;
            $width = $colspan[$col] ?? 1;
            $cells[] = [
                'content' => $isEmpty ? '' : $cellData['content'],
                'attributes' => $isEmpty ? '' : $cellData['attributes'],
                'colspan' => max(1, $width),
                'gridColumn' => $col,
                'isEmpty' => $isEmpty,
            ];
        }

        return ['cells' => $cells, 'consumedRowspanColumns' => $consumedRowspanColumns];
    }

    /**
     * Check if a row with unclosed code spans can be closed by continuation rows.
     *
     * This looks ahead for continuation rows and checks if merging their content
     * would result in balanced code spans.
     *
     * @param array<string> $lines All lines
     * @param int $start Starting line index
     * @param int $count Total line count
     *
     * @return bool True if continuation rows can close the code spans
     */
    protected function canCloseCodeSpanWithContinuations(array $lines, int $start, int $count): bool
    {
        $baseLine = $lines[$start];

        // Parse cells from base row (using raw parsing that ignores code span issues)
        $baseCells = $this->tableParser->parseTableCellsRaw($baseLine);
        if ($baseCells === []) {
            return false;
        }

        $mergedCells = $baseCells;
        $i = $start + 1;

        // Look for continuation rows
        while ($i < $count && $this->tableParser->isContinuationRow($lines[$i])) {
            $continuationCells = $this->tableParser->parseContinuationCells($lines[$i]);
            $mergedCells = $this->tableParser->mergeCellContents($mergedCells, $continuationCells);
            $i++;
        }

        // Check if we found any continuations and if merged content is valid
        if ($i === $start + 1) {
            // No continuation rows found
            return false;
        }

        return $this->tableParser->mergedCellsAreValid($mergedCells);
    }

    /**
     * Skip footnote definitions (already extracted in first pass)
     *
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseFootnoteDefinition(array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Match footnote definition: [^label]: content
        if (!preg_match('/^\[\^([^\]]+)\]:(?: |[ \t]*$)/', $line)) {
            return null;
        }

        // Skip the footnote definition and any continuation lines
        $i = $start + 1;
        $count = count($lines);

        while ($i < $count) {
            $nextLine = $lines[$i];
            if (IndentationHelper::isBlankLine($nextLine)) {
                // A blank line continues the footnote only if a >= 2-indented
                // line follows; otherwise it ends the footnote. Must mirror the
                // body-collection logic so a line is never skipped here without
                // being collected there (grammar PART 9 §16).
                if ($i + 1 < $count && preg_match('/^(?:[ ]{2}|\t)/', $lines[$i + 1])) {
                    $i++;

                    continue;
                }

                break;
            }
            if (preg_match('/^(?:[ ]{2}|\t)/', $nextLine)) {
                $i++;
            } else {
                break;
            }
        }

        return $i - $start;
    }

    /**
     * Skip reference definitions (already extracted in first pass)
     *
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseReferenceDefinition(array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Match reference definition: [label]: url (url can be empty, on next line)
        if (!preg_match('/^\[(?!@)([^\]]+)\]:(?: +(.*)|\s*)$/', $line, $matches)) {
            return null;
        }

        // Collect continuation lines
        $i = $start + 1;
        $count = count($lines);

        while ($i < $count) {
            $nextLine = $lines[$i];
            if (IndentationHelper::isBlankLine($nextLine)) {
                break;
            }
            // Check if next line starts a new reference definition
            if (preg_match('/^\[(?!@)([^\]]+)\]: /', $nextLine)) {
                break;
            }
            if ($this->startsNewBlock($nextLine)) {
                break;
            }
            // A list marker (bullet or ordered, at any indent) starts a list,
            // not a definition continuation; stop the skip so it is parsed.
            if ($this->listParser->parseListItemMarker(ltrim($nextLine)) !== null) {
                break;
            }
            if (preg_match('/^\s+(\S.*)$/', $nextLine, $contMatch)) {
                $i++;
            } else {
                break;
            }
        }

        return $i - $start;
    }

    /**
     * Skip abbreviation definitions (already extracted in first pass)
     *
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseAbbreviationDefinition(array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Match abbreviation definition: *[abbr]: definition
        if (!$this->isAbbreviationDefinitionLine($line)) {
            return null;
        }

        // Collect continuation lines
        $i = $start + 1;
        $count = count($lines);

        while ($i < $count) {
            $nextLine = $lines[$i];
            if (IndentationHelper::isBlankLine($nextLine)) {
                break;
            }
            // Check if next line starts a new abbreviation definition
            if ($this->isAbbreviationDefinitionLine($nextLine)) {
                break;
            }
            if ($this->startsNewBlock($nextLine)) {
                break;
            }
            // A list marker (bullet or ordered, at any indent) starts a list,
            // not a definition continuation; stop the skip so it is parsed.
            if ($this->listParser->parseListItemMarker(ltrim($nextLine)) !== null) {
                break;
            }
            if (preg_match('/^\s+(.+)$/', $nextLine)) {
                $i++;
            } else {
                break;
            }
        }

        return $i - $start;
    }

    protected function isAbbreviationDefinitionLine(string $line): bool
    {
        return preg_match(self::ABBREVIATION_DEFINITION_PATTERN, $line) === 1;
    }

    /**
     * @param \Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseParagraph(Node $parent, array $lines, int $start): int
    {
        $line = $lines[$start];
        // Strip leading whitespace from first line (matching JS reference)
        $content = ltrim($line);

        $i = $start + 1;
        $count = count($lines);

        // Track brace nesting incrementally. Re-scanning the whole (growing)
        // $content on every continuation line made paragraph parsing O(n^2) in
        // the number of lines; carrying the state forward keeps it linear.
        $braceState = $this->scanBraceState($content, self::INITIAL_BRACE_STATE);

        while ($i < $count) {
            $nextLine = $lines[$i];

            if (IndentationHelper::isBlankLine($nextLine)) {
                break;
            }

            // An unclosed brace in the content so far suppresses block
            // interruption (e.g. `text{a=x` then `# not-a-heading`).
            if ($braceState['depth'] <= 0 && $this->interruptsParagraph($lines, $i, $content, $start)) {
                break;
            }

            // Strip leading whitespace from continuation lines (matching JS reference)
            $nextLine = ltrim($nextLine);
            $segment = "\n" . $nextLine;
            $content .= $segment;
            $braceState = $this->scanBraceState($segment, $braceState);
            $i++;
        }

        $paragraph = new Paragraph();
        $this->inlineParser->parse($paragraph, $content, $start);
        $this->applyPendingAttributes($paragraph);
        $parent->appendChild($paragraph);

        return $i - $start;
    }

    /**
     * Paragraph interruption (grammar PART 9 §10). Visible blocks interrupt
     * open paragraphs without requiring a blank line. Captions and invisible
     * constructs (reference definitions and comments) also interrupt, since
     * they annotate/attach to prose with no rendered block of their own.
     * Sublist nesting via indentation is handled in the list-item collector.
     *
     * @param array<string> $lines
     * @param int $i
     * @param string $content Current paragraph content before the candidate line.
     * @param int $sourceLine
     */
    protected function interruptsParagraph(array $lines, int $i, string $content, int $sourceLine): bool
    {
        $line = $lines[$i];

        if (($line[0] ?? '') === '^' && ($line[1] ?? '') === ' ') {
            return $this->isCaptionableParagraphContent($content, $sourceLine);
        }

        if ($this->startsNewBlock($line, $lines, $i)) {
            return true;
        }

        // A standalone block-attribute line floats forward to the next block
        // (or is dropped when none follows), so it interrupts the paragraph
        // rather than folding in as literal text (grammar PART 9 §15).
        if ($this->isBlockAttributeLine($line)) {
            return true;
        }

        // Invisible constructs produce no rendered block of their own, so they
        // are recognised next to prose rather than left as literal text.
        return preg_match('/^\[[^\]]+\]: /', $line) === 1
            || $this->isAbbreviationDefinitionLine($line)
            || preg_match('/^%%/', $line) === 1;
    }

    protected function isCaptionableParagraphContent(string $content, int $sourceLine): bool
    {
        $paragraph = new Paragraph();
        $this->inlineParser->parse($paragraph, $content, $sourceLine);

        $children = $paragraph->getChildren();

        return count($children) === 1
            && (
                $children[0] instanceof Image
                || ($children[0] instanceof Math && $children[0]->isDisplay())
            );
    }

    /**
     * Whether a line is a standalone single-line block-attribute line: a
     * `{...}` block alone on the line that yields attributes (matching the
     * single-line case recognised by tryParseBlockAttributes). Braced inline
     * markers (`_ * = + - ~ ^`) and comment blocks (`%`) are excluded.
     */
    protected function isBlockAttributeLine(string $line): bool
    {
        $attrStr = $this->parseSingleLineBlockAttributePayload($line);
        if ($attrStr === null) {
            return false;
        }

        return preg_match('/^[.#a-zA-Z]/', $attrStr) === 1 && !str_starts_with($attrStr, '%');
    }

    /**
     * Normalize one standalone single-line block-attribute line. Adjacent
     * `{...}` blocks merge as if their contents were separated by spaces.
     */
    protected function parseSingleLineBlockAttributePayload(string $line): ?string
    {
        $line = rtrim($line, " \t");
        $length = strlen($line);
        if ($length === 0 || $line[0] !== '{') {
            return null;
        }

        $parts = [];
        $pos = 0;
        while ($pos < $length) {
            if ($line[$pos] !== '{') {
                return null;
            }

            $end = $this->findSingleLineAttributeBlockEnd($line, $pos);
            if ($end === null) {
                return null;
            }

            $parts[] = trim(substr($line, $pos + 1, $end - $pos - 1));
            $pos = $end + 1;
        }

        return trim(implode(' ', $parts));
    }

    protected function findSingleLineAttributeBlockEnd(string $line, int $start): ?int
    {
        $length = strlen($line);
        $quote = null;
        for ($i = $start + 1; $i < $length; $i++) {
            $char = $line[$i];
            if ($char === "\n") {
                return null;
            }
            if ($char === '\\' && $i + 1 < $length) {
                $i++;

                continue;
            }
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }
            if ($char === '}') {
                return $i;
            }
        }

        return null;
    }

    /**
     * Try to parse a caption line (^ caption text).
     *
     * Captions apply to the immediately preceding block:
     * - Table → adds <caption> element
     * - Paragraph with single Image → wraps in <figure> with <figcaption>
     * - BlockQuote → wraps in <figure> with <figcaption>
     *
     * @param \Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseCaption(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Caption syntax: `^ caption text` (caret followed by space)
        if (!preg_match('/^\^ (.*)$/', $line, $matches)) {
            return null;
        }

        $captionLines = [$matches[1]];
        $i = $start + 1;
        $count = count($lines);

        // Caption can continue on non-blank lines that don't start a new block
        while ($i < $count) {
            $nextLine = $lines[$i];
            if (IndentationHelper::isBlankLine($nextLine)) {
                break;
            }
            // Stop at block-level elements
            if ($this->startsNewBlock($nextLine, $lines, $i)) {
                break;
            }
            // Stop at new table
            if (preg_match('/^\|/', $nextLine)) {
                break;
            }
            $captionLines[] = $nextLine;
            $i++;
        }

        $captionText = implode("\n", $captionLines);

        // Get the last child to attach the caption to
        $children = $parent->getChildren();
        if (!$children) {
            // No preceding block to attach caption to - treat as regular paragraph
            return null;
        }

        $lastChild = $children[count($children) - 1];

        $linesConsumed = $i - $start;

        // Handle Table - add caption directly to table
        if ($lastChild instanceof Table) {
            $caption = new Caption();
            $this->inlineParser->parse($caption, $captionText, $start, true);
            $lastChild->setCaption($caption);

            return $linesConsumed;
        }

        // Handle CodeBlock - wrap in figure (numbered listing)
        if ($lastChild instanceof CodeBlock) {
            $figure = new Figure();

            // A preceding block-attribute line (e.g. `{#lst-x}`) sits on the
            // code block; move it onto the figure so the id drives the crossref.
            foreach ($lastChild->getAttributes() as $key => $value) {
                $figure->setAttribute($key, $value);
                $lastChild->removeAttribute($key);
            }

            $caption = new Caption();
            $this->inlineParser->parse($caption, $captionText, $start, true);

            $figure->appendChild($lastChild);
            $figure->appendChild($caption);

            $parent->replaceChild(count($children) - 1, $figure);

            return $linesConsumed;
        }

        // Handle BlockQuote - wrap in figure
        if ($lastChild instanceof BlockQuote) {
            $figure = new Figure();

            // Transfer attributes from blockquote to figure
            foreach ($lastChild->getAttributes() as $key => $value) {
                $figure->setAttribute($key, $value);
                $lastChild->removeAttribute($key);
            }

            // Create caption
            $caption = new Caption();
            $this->inlineParser->parse($caption, $captionText, $start, true);

            // Build figure: blockquote + caption
            $figure->appendChild($lastChild);
            $figure->appendChild($caption);

            // Replace blockquote with figure in parent
            $parent->replaceChild(count($children) - 1, $figure);

            return $linesConsumed;
        }

        // Handle Paragraph containing only an Image - wrap in figure
        if ($lastChild instanceof Paragraph) {
            $paragraphChildren = $lastChild->getChildren();
            if (count($paragraphChildren) === 1 && $paragraphChildren[0] instanceof Image) {
                $image = $paragraphChildren[0];

                $figure = new Figure();

                // A preceding block-attribute line (carried on the paragraph)
                // floats onto the figure. The image's OWN trailing attributes
                // stay on the <img> -- the same target as a standalone block
                // image -- so they are NOT transferred to the figure.
                foreach ($lastChild->getAttributes() as $key => $value) {
                    $figure->setAttribute($key, $value);
                }

                // Create caption
                $caption = new Caption();
                $this->inlineParser->parse($caption, $captionText, $start, true);

                // Build figure: image + caption
                $figure->appendChild($image);
                $figure->appendChild($caption);

                // Replace paragraph with figure in parent
                $parent->replaceChild(count($children) - 1, $figure);

                return $linesConsumed;
            }

            // A paragraph that is nothing but a display-math span is a numbered
            // EQUATION: wrap the whole paragraph (keeping the <p> wrapper) in a
            // figure. Inline math, or display math with trailing prose, does not
            // qualify (more than one child, or not display).
            if (
                count($paragraphChildren) === 1
                && $paragraphChildren[0] instanceof Math
                && $paragraphChildren[0]->isDisplay()
            ) {
                $figure = new Figure();

                // A preceding block-attribute line (`{#eq-x}`) sits on the
                // paragraph; move it onto the figure so the id is on <figure>,
                // not the inner <p>, and drives the crossref.
                foreach ($lastChild->getAttributes() as $key => $value) {
                    $figure->setAttribute($key, $value);
                }
                foreach (array_keys($lastChild->getAttributes()) as $key) {
                    $lastChild->removeAttribute($key);
                }

                $caption = new Caption();
                $this->inlineParser->parse($caption, $captionText, $start, true);

                $figure->appendChild($lastChild);
                $figure->appendChild($caption);

                $parent->replaceChild(count($children) - 1, $figure);

                return $linesConsumed;
            }
        }

        // No valid preceding block for caption - treat as regular paragraph
        return null;
    }

    protected function appendToLastParagraph(Node $parent, string $content, int $line): void
    {
        $children = $parent->getChildren();
        $lastChild = $children[count($children) - 1] ?? null;

        if ($lastChild instanceof Paragraph) {
            $this->inlineParser->parse($lastChild, ' ' . $content, $line);
        }
    }

    /**
     * Whether a line ENDS an open heading (and starts a sibling block). A list
     * marker (bullet, task, or ordered) ends a heading and starts a sibling
     * list: a heading is a bounded title, so a list marker folds into a
     * PARAGRAPH but never into a heading. Every paragraph-interrupter ends the
     * heading too. (Block quotes use endsBlockQuote(), which lets a list marker
     * fold into the open quoted paragraph instead.)
     *
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function endsHeadingOrQuote(string $line, ?array $lines = null, ?int $index = null): bool
    {
        if ($this->listParser->parseListItemMarker(ltrim($line)) !== null) {
            return true;
        }

        return $this->startsNewBlock($line, $lines, $index);
    }

    /**
     * Whether a non-">" line ENDS an open block quote (and starts a sibling
     * block) during lazy continuation. A list marker (bullet OR ordered) ends
     * the quote UNLESS an open plain paragraph precedes it: when one does, the
     * marker folds into that paragraph as literal text (the top-level rule that
     * a list marker does not interrupt an open paragraph, applied inside the
     * quote). After a heading, table, fenced code, thematic break, `:::` div,
     * or a blank line there is no open paragraph to fold into, so a list marker
     * ENDS the quote and starts a sibling list -- mirroring the top level,
     * where `# h\n- item` is a heading plus a sibling list. Visible
     * block-openers, invisible constructs, and captions still end the quote via
     * startsNewBlock().
     *
     * @param string $line
     * @param bool $paragraphTextOpen Whether an open plain paragraph precedes this line.
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function endsBlockQuote(
        string $line,
        bool $paragraphTextOpen,
        ?array $lines = null,
        ?int $index = null,
    ): bool {
        // A list marker ends the quote only when there is no open paragraph to
        // fold into; with an open paragraph it folds (does not end the quote).
        if (!$paragraphTextOpen && $this->listParser->parseListItemMarker(ltrim($line)) !== null) {
            return true;
        }

        return $this->startsNewBlock($line, $lines, $index);
    }

    /**
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function startsNewBlock(string $line, ?array $lines = null, ?int $index = null): bool
    {
        // Quick check: empty lines don't start blocks
        if ($line === '' || !isset($line[0])) {
            return false;
        }

        // Caption `^ text` can always interrupt paragraphs (special case for figure captions)
        // Quick first-char check before regex
        if ($line[0] === '^' && isset($line[1]) && $line[1] === ' ') {
            return true;
        }

        // Fenced comments `%%%` can always interrupt paragraphs
        // Comments should be invisible and not require extra formatting
        if ($line[0] === '%' && isset($line[1], $line[2]) && $line[1] === '%' && $line[2] === '%') {
            return true;
        }

        return $this->startsInterruptingBlock($line, $lines, $index);
    }

    /**
     * Check if line starts a visible block that interrupts an open paragraph.
     *
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function startsInterruptingBlock(string $line, ?array $lines = null, ?int $index = null): bool
    {
        // SYMMETRIC LIST INTERRUPTION: no list marker interrupts a paragraph --
        // a bullet (`-`/`*`) needs a blank line before it, exactly like an
        // ordered marker (`1.`/`a.`/`i.`) already does. This drops the former
        // "Rule B" (an indented bullet at ANY indentation interrupted a
        // paragraph), so there is no indented-bullet arm here and the column-0
        // `-`/`*` arm below no longer returns true for a bullet. Tight nested
        // lists are unaffected: sublist nesting runs through
        // isBlockElementStart(), not this paragraph-interruption predicate.

        // Use first-char switch to avoid unnecessary regex checks
        $first = $line[0];

        switch ($first) {
            case '#':
                // Headings: #{1,6}, a space, then non-empty content (a bare
                // `#` / `# ` is not a heading).
                return preg_match('/^#{1,6} .*\S/', $line) === 1;
            case '-':
            case '*':
                // A bullet does NOT interrupt a paragraph (symmetric with ordered
                // markers; needs a blank line). Only a thematic break -- a bare
                // run of at least three matching markers -- interrupts here.
                return preg_match('/^(' . preg_quote($first, '/') . '[ \t]*){3,}$/', $line) === 1;
            case '+':
                // `+` is the list-continuation marker, NOT a bullet (only the
                // opt-in PlusBulletExtension re-enables it) and is not a
                // thematic-break char. A bare `+ x` line is ordinary prose, so
                // it must not interrupt -- otherwise "+ one\n+ two" splits into
                // two stray paragraphs that are neither prose nor a list.
                return false;
            case '_':
                // Thematic break
                return preg_match('/^(_[ \t]*){3,}$/', $line) === 1;
            case '|':
                // Tables: a single "| a | b |" row is a valid table, but a pipe
                // in prose ("a\n| b als Oder.") is not a row, so validate before
                // interrupting to avoid splitting prose into stray paragraphs.
                return $this->tableParser->isTableRow($line);
            case '>':
                // Block quotes
                return true;
            case '`':
            case '~':
                // Code fences interrupt only if a matching closer exists ahead.
                return $this->hasClosingFenceAhead($line, $lines, $index);
            case ':':
                // Fenced divs interrupt only if a matching closer exists ahead.
                return $this->hasClosingDivFenceAhead($line, $lines, $index);
            case '%':
                // Fenced comments: %{3,}
                return isset($line[1], $line[2]) && $line[1] === '%' && $line[2] === '%';
            default:
                // An ordered-list marker does NOT interrupt a paragraph: it
                // needs a blank line (matching Djot). Allowing it would require
                // the CommonMark `1.`-only heuristic to keep `2.`, `1985.` etc.
                // as prose, which Carve avoids. A bare image is inline too.
                return false;
        }
    }

    /**
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function hasClosingFenceAhead(string $line, ?array $lines, ?int $index): bool
    {
        if (preg_match('/^([`~])\1{2,}/', $line, $matches) !== 1) {
            return false;
        }

        if ($lines === null || $index === null) {
            return true;
        }

        $char = $matches[1];
        $length = strspn($line, $char);
        $count = count($lines);

        // Reuse the collector's closer matcher so the interruption lookahead can
        // never accept a closer the fence collector would reject (no drift).
        for ($i = $index + 1; $i < $count; $i++) {
            if ($this->fencedBlockParser->isCodeFenceCloser($lines[$i], $char, $length)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function hasClosingDivFenceAhead(string $line, ?array $lines, ?int $index): bool
    {
        if (preg_match('/^:{3,}/', $line) !== 1) {
            return false;
        }

        if ($lines === null || $index === null) {
            return true;
        }

        $length = strspn($line, ':');
        $count = count($lines);

        // Reuse the collector's closer matcher (isDivFenceCloser allows no leading
        // whitespace), so an indented `  :::` is not mistaken for a closer here
        // when tryParseDiv would not accept it -- which would split the paragraph
        // and swallow the document into an unterminated div. Skip fenced code and
        // raw ``` =format blocks too: a ::: line inside one is NOT a div closer,
        // and tryParseDiv's suffix scan ignores it -- so this lookahead must
        // agree, or the paragraph is split while no div is ever produced.
        $inCodeBlock = false;
        $codeBlockFence = '';
        $codeBlockFenceLength = 0;
        for ($i = $index + 1; $i < $count; $i++) {
            $currentLine = $lines[$i];
            if (!$inCodeBlock) {
                $rawFenceInfo = $this->fencedBlockParser->parseRawBlockOpener($currentLine);
                if (
                    $rawFenceInfo !== null
                    && $this->hasCodeFenceCloserAhead($lines, $i, $rawFenceInfo['fence'][0], $rawFenceInfo['length'])
                ) {
                    $inCodeBlock = true;
                    $codeBlockFence = $rawFenceInfo['fence'][0];
                    $codeBlockFenceLength = $rawFenceInfo['length'];

                    continue;
                }
                $codeFenceInfo = $this->fencedBlockParser->parseCodeFenceOpener($currentLine);
                if ($codeFenceInfo !== null) {
                    $inCodeBlock = true;
                    $codeBlockFence = $codeFenceInfo['char'];
                    $codeBlockFenceLength = $codeFenceInfo['length'];

                    continue;
                }
            }
            if ($inCodeBlock) {
                if ($this->fencedBlockParser->isCodeFenceCloser($currentLine, $codeBlockFence, $codeBlockFenceLength)) {
                    $inCodeBlock = false;
                }

                continue;
            }
            if ($this->fencedBlockParser->isDivFenceCloser($currentLine, $length)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Advance the trailing-block tracker by one collected item content line.
     *
     * Tracks the kind of the item's most recent top-level block so the
     * lazy-continuation gate can answer, in O(1) per line, whether a dedented
     * plain-text line may lazily continue an OPEN paragraph (CommonMark lazy
     * continuation).
     *
     * `openParagraph` is true for a trailing paragraph -- including the open
     * paragraph at the end of a blockquote, div, or heading text -- so a
     * dedented line folds into it. It is false for a trailing fenced code block
     * or table (no open paragraph); a dedented line after one of those ends the
     * item and becomes a top-level block instead of being absorbed.
     *
     * The lines are already stripped to content-relative indentation, so a
     * fence or a table row sits at column 0 here. State is carried across
     * lines (`inFence` + fence char/length) so a multi-line fenced block keeps
     * `openParagraph` false until its closer is seen and the trailing block
     * changes. The tracker is intentionally narrow: it reports "no open
     * paragraph" only for a trailing fenced code block or table, leaving every
     * other shape to the existing lazy-continuation behavior.
     *
     * @param array{openParagraph: bool, inFence: bool, fenceChar: string, fenceLength: int, inDiv: bool, divFenceLength: int} $state
     * @param string $line Collected line, stripped to content-relative indentation.
     *
     * @return array{openParagraph: bool, inFence: bool, fenceChar: string, fenceLength: int, inDiv: bool, divFenceLength: int}
     */
    protected function advanceTrailingBlockState(array $state, string $line): array
    {
        if ($state['inFence']) {
            // Inside a fenced code block: stay code (no open paragraph) until
            // the matching closer is seen. The closer itself is still part of
            // the code block, so the trailing block remains code.
            if ($this->fencedBlockParser->isCodeFenceCloser($line, $state['fenceChar'], $state['fenceLength'])) {
                $state['inFence'] = false;
            }
            $state['openParagraph'] = false;

            return $state;
        }

        if ($state['inDiv']) {
            // Inside a `:::` div / admonition: a complete (closed) div has no
            // open paragraph, so the trailing block stays non-paragraph through
            // the body and the closing fence. An UNTERMINATED div (closer never
            // seen) is handled at the gate via inDiv, which keeps it foldable
            // (it is paragraph text under the §10 closer-lookahead rule).
            if ($this->fencedBlockParser->isDivFenceCloser($line, $state['divFenceLength'])) {
                $state['inDiv'] = false;
            }
            $state['openParagraph'] = false;

            return $state;
        }

        if (IndentationHelper::isBlankLine($line)) {
            // A blank line closes the current block. Until a fresh block opens,
            // a dedented line is a new top-level block, not a continuation.
            $state['openParagraph'] = false;

            return $state;
        }

        $opener = $this->fencedBlockParser->parseCodeFenceOpener($line);
        if ($opener !== null) {
            /** @var string $fenceChar */
            $fenceChar = $opener['char'];
            /** @var int $fenceLength */
            $fenceLength = $opener['length'];
            $state['inFence'] = true;
            $state['fenceChar'] = $fenceChar;
            $state['fenceLength'] = $fenceLength;
            $state['openParagraph'] = false;

            return $state;
        }

        $divOpener = $this->fencedBlockParser->parseDivFenceOpener($line);
        if ($divOpener !== null) {
            /** @var int $divFenceLength */
            $divFenceLength = $divOpener['length'];
            $state['inDiv'] = true;
            $state['divFenceLength'] = $divFenceLength;
            $state['openParagraph'] = false;

            return $state;
        }

        if ($this->tableParser->isTableRow($line)) {
            // A table has no open paragraph for a dedented line to continue.
            $state['openParagraph'] = false;

            return $state;
        }

        // Any other non-blank line belongs to a paragraph-bearing block (plain
        // paragraph, blockquote, heading text). Treat the trailing block
        // as having an open paragraph and let the existing lazy-continuation
        // behavior fold the dedented line in.
        $state['openParagraph'] = true;

        return $state;
    }

    /**
     * Check if line starts a block element that should terminate list content collection.
     *
     * This is different from startsNewBlock() which is about paragraph interruption.
     * Block elements at column 0 (or less than list indent) should always break out
     * of list content collection.
     *
     * @param string $line The trimmed line to check
     */
    protected function isBlockElementStart(string $line): bool
    {
        // Headings: #{1,6}, a space, then non-empty content (a bare `#` / `# `
        // is not a heading).
        if (preg_match('/^#{1,6} .*\S/', $line)) {
            return true;
        }

        // Code fences (``` or ~~~)
        if (preg_match('/^[`~]{3,}/', $line)) {
            return true;
        }

        // Fenced divs (::: but not definition list :)
        if (preg_match('/^:{3,}/', $line)) {
            return true;
        }

        // Comment fences (%%%)
        if (preg_match('/^%{3,}/', $line)) {
            return true;
        }

        // Thematic breaks (---, ***, ___)
        if (preg_match('/^([-*_])[ \t]*\1[ \t]*\1/', $line)) {
            return true;
        }

        // Block quotes
        if (preg_match('/^>/', $line)) {
            return true;
        }

        // Tables (starting with |)
        if (preg_match('/^\|/', $line)) {
            return true;
        }

        // Definition list terms (: followed by space or content)
        if (preg_match('/^: /', $line)) {
            return true;
        }

        // List markers - these indicate a new list at this level. A marker is a
        // list only with non-empty content (a content-less `- ` is paragraph
        // text, not a block start), matching ListParser::parseListItemMarker.
        // Bullet lists: - or * followed by space + content (`+` is not a bullet
        // in Carve -- it is the list-continuation marker).
        if (preg_match('/^[-*] +\S/', $line)) {
            return true;
        }

        // Ordered lists: digit(s) or letter followed by . or ) and space + content
        if (preg_match('/^(\d+|[a-zA-Z])[.)] +\S/', $line)) {
            return true;
        }

        // Task lists: - [ ] or - [x]
        if (preg_match('/^- \[[xX ]\] /', $line)) {
            return true;
        }

        return false;
    }

    /**
     * Check if text has an unclosed brace (for attribute blocks)
     */
    protected function hasUnclosedBrace(string $text): bool
    {
        return $this->scanBraceState($text, self::INITIAL_BRACE_STATE)['depth'] > 0;
    }

    /**
     * Scan a text segment for brace nesting, carrying state across segments.
     *
     * Used to detect an unclosed attribute brace in a paragraph (`text{a=x`)
     * without re-scanning the whole accumulated content on every continuation
     * line. Quote state, brace depth and a dangling backslash (an escape that
     * straddles the segment boundary) are threaded through so scanning a string
     * in one call or split across calls yields the identical result.
     *
     * @param string $segment
     * @param array{depth: int, inQuote: bool, quoteChar: string, pendingEscape: bool} $state
     *
     * @return array{depth: int, inQuote: bool, quoteChar: string, pendingEscape: bool}
     */
    protected function scanBraceState(string $segment, array $state): array
    {
        $depth = $state['depth'];
        $inQuote = $state['inQuote'];
        $quoteChar = $state['quoteChar'];
        $len = strlen($segment);
        $i = 0;

        // A backslash at the end of the previous segment escapes this segment's
        // first character.
        if ($state['pendingEscape'] && $len > 0) {
            $i = 1;
        }
        $pendingEscape = false;

        for (; $i < $len; $i++) {
            $char = $segment[$i];

            // Handle escape sequences
            if ($char === '\\') {
                if ($i + 1 < $len) {
                    $i++;

                    continue;
                }

                // Trailing backslash escapes the next segment's first character.
                $pendingEscape = true;

                break;
            }

            // Handle quotes
            if (!$inQuote && ($char === '"' || $char === "'")) {
                $inQuote = true;
                $quoteChar = $char;

                continue;
            }

            if ($inQuote && $char === $quoteChar) {
                $inQuote = false;

                continue;
            }

            // Count braces only outside quotes
            if (!$inQuote) {
                if ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;
                }
            }
        }

        return ['depth' => $depth, 'inQuote' => $inQuote, 'quoteChar' => $quoteChar, 'pendingEscape' => $pendingEscape];
    }

    /**
     * @return array<string>
     */
    protected function splitLines(string $input): array
    {
        return explode("\n", str_replace(["\r\n", "\r"], "\n", $input));
    }

    /**
     * Validate reference definitions vs usage
     * Generates warnings for unused references.
     * Note: Undefined references are warned about inline during parsing.
     */
    protected function validateReferences(): void
    {
        // Check for unused reference definitions (defined but never used)
        // Skip heading auto-references (URLs start with #)
        // Skip footnote definitions (labels start with ^)
        foreach ($this->references as $label => $def) {
            if (
                !isset($this->usedReferences[$label])
                && !str_starts_with($def->url, '#')
                && !str_starts_with($label, '^')
            ) {
                $this->addWarning(
                    "Reference '{$label}' defined but never used",
                    $def->line,
                    1,
                    false,
                    'reference',
                    null,
                );
            }
        }
    }

    public function getReference(string $label): ?ReferenceDefinition
    {
        return $this->references[$label] ?? null;
    }

    public function getCollapsedReference(string $label): ?ReferenceDefinition
    {
        return $this->references[$label] ?? $this->headingReferencesByFoldedLabel[$this->foldReferenceLabel($label)] ?? null;
    }

    protected function registerHeadingReference(string $label, ReferenceDefinition $reference): void
    {
        if (!isset($this->references[$label])) {
            $this->references[$label] = $reference;
        }

        $this->headingReferencesByFoldedLabel[$this->foldReferenceLabel($label)] ??= $reference;
    }

    protected function foldReferenceLabel(string $label): string
    {
        return (string)preg_replace_callback(
            '/./us',
            static fn (array $m): string => mb_strtolower($m[0], 'UTF-8'),
            $label,
        );
    }

    /**
     * Mark a reference as used (for validation warnings)
     * Only tracks when collectWarnings is enabled.
     */
    public function markReferenceUsed(string $label, int $line): void
    {
        if ($this->collectWarnings && !isset($this->usedReferences[$label])) {
            $this->usedReferences[$label] = $line;
        }
    }

    public function hasFootnote(string $label): bool
    {
        return isset($this->footnotes[$label]);
    }

    /**
     * Get all abbreviation definitions
     *
     * @return array<string, string> Map of abbreviation text to definition
     */
    public function getAbbreviations(): array
    {
        return $this->abbreviations;
    }

    /**
     * Get the definition for a specific abbreviation
     */
    public function getAbbreviation(string $abbr): ?string
    {
        return $this->abbreviations[$abbr] ?? null;
    }

    /**
     * Add warning for undefined reference (called from InlineParser)
     */
    public function addUndefinedReferenceWarning(string $ref, int $line, int $column): void
    {
        $this->addWarning(
            "Undefined reference '{$ref}'",
            $line,
            $column,
            false,
            'reference',
            "Define with [{$ref}]: url or use inline link",
        );
    }

    /**
     * Add warning for undefined footnote (called from InlineParser)
     */
    public function addUndefinedFootnoteWarning(string $label, int $line, int $column): void
    {
        $this->addWarning("Undefined footnote '{$label}'", $line, $column, false);
    }

    /**
     * Track an anchor link for validation (called from InlineParser)
     * Only tracks when collectWarnings is enabled.
     */
    public function trackAnchorLink(string $fragment, int $line, int $column): void
    {
        if ($this->collectWarnings) {
            $this->anchorLinks[] = [
                'fragment' => $fragment,
                'line' => $line,
                'column' => $column,
            ];
        }
    }

    /**
     * Validate anchor links point to existing IDs in the document
     *
     * Checks all links with `#fragment` destinations against:
     * - Heading IDs (from heading auto-references)
     * - Explicit `{#id}` attributes on any element
     */
    protected function validateAnchorLinks(Document $document): void
    {
        if ($this->anchorLinks === []) {
            return;
        }

        // Collect all known anchor targets
        $knownIds = $this->headingIds;

        // From explicit {#id} attributes on any node in the AST
        $this->collectExplicitIds($document, $knownIds);

        // Validate each tracked anchor link. Matching is exact (case-sensitive):
        // a plain `[link](#fragment)` href is emitted verbatim and HTML fragment
        // navigation is case-sensitive, so a `#my-heading` link to a
        // case-preserved `My-Heading` id is genuinely broken and must warn.
        // (Contrast `</#id>` crossrefs, which rewrite the href to the resolved
        // id and so resolve case-insensitively.)
        foreach ($this->anchorLinks as $anchor) {
            if (!isset($knownIds[$anchor['fragment']])) {
                $this->addWarning(
                    "Broken anchor link '#{$anchor['fragment']}' — no element with this ID exists",
                    $anchor['line'],
                    $anchor['column'],
                    false,
                    'anchor',
                    null,
                );
            }
        }
    }

    /**
     * Recursively collect explicit {#id} attributes from the AST
     *
     * @param \Carve\Node\Node $node
     * @param array<string, bool> $ids
     */
    protected function collectExplicitIds(Node $node, array &$ids): void
    {
        if ($node->hasAttribute('id')) {
            $id = $node->getAttribute('id');
            if ($id !== null && $id !== '') {
                $ids[$id] = true;
            }
        }

        foreach ($node->getChildren() as $child) {
            $this->collectExplicitIds($child, $ids);
        }
    }

    /**
     * Get the inline parser for registering custom patterns
     */
    public function getInlineParser(): InlineParser
    {
        return $this->inlineParser;
    }

    /**
     * Get the list parser for tweaking list parsing (e.g. bullet markers)
     */
    public function getListParser(): ListParser
    {
        return $this->listParser;
    }

    /**
     * Check if text contains only plain characters (no inline markup triggers).
     *
     * Used to skip the inline parser for simple table cell content,
     * creating a Text node directly instead.
     */
    protected function isPlainText(string $text): bool
    {
        // Can't shortcut if custom patterns or abbreviations are registered
        if ($this->inlineParser->getInlinePatterns() || $this->abbreviations) {
            return false;
        }

        // Check for any character that triggers inline parsing. Includes
        // Carve's delimiters: / (italic), and , / = (the ,, subscript
        // and == highlight pairs).
        return strpbrk($text, '\\`*_[{^~<$:!"\'-.\n/,=') === false;
    }
}
