<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Markdown;

use Closure;
use MarkupCarve\Carve\Converter\MigrationDiagnostic;

final class ReferenceStage
{
    /**
     * How many lines a definition spread inside a quote is read across.
     *
     * @var int
     */
    private const SPREAD_DEFINITION_LINES = 32;

    /**
     * @var string
     */
    private const THEMATIC_BREAK = '/^([-*_])(?:[ \t]*\1){2,}[ \t]*$/';

    /**
     * @var string
     */
    private const ESCAPED_LABEL_CLOSER = '/^ {0,3}\[(?:[^\]\\\\\n]|\\\\[^\]\n])*\\\\\]:/';

    /**
     * @var string
     */
    private const EMPTY_DEFINITION_ITEM_SENTINEL = "\x00CARVE_EMPTY_DEFINITION_ITEM\x00";

    public function __construct(
        private readonly ReferenceContext $context,
        private readonly InlineContext $inlineContext,
        private readonly InlineStage $inlineStage,
        private readonly Closure $escapeBlockOpener,
        private readonly Closure $columnWidth,
        private readonly Closure $htmlBlockInterrupts,
        private readonly Closure $indentWidth,
        private readonly Closure $expandLeadingTabs,
        private readonly Closure $htmlBlockCloser,
        private readonly Closure $itemContentColumn,
    ) {
    }

    /**
     * Take reference definitions out of the body: one with an empty destination
     * is dropped, recording each label whose FIRST definition is one (CommonMark:
     * the first one wins), and every other one is kept for the end of the
     * document.
     *
     * @param array<string> $lines
     * @param array<int, true>|null $blockHtml Filled, when given, with the index
     *   of every line this pass reads as HTML BLOCK content - a carrier marker
     *   standing behind a task box included, since `[ ] ` is inline content and
     *   no HTML block can begin after it (markup-carve/carve#2850).
     * @param array<int, true>|null $paragraphLines Paragraph continuation lines.
     *
     * @return array<string>
     */
    public function extractReferenceDefinitions(array $lines, ?array &$blockHtml = null, ?array &$paragraphLines = null): array
    {
        if (!$this->context->importingFootnoteBody) {
            $this->inlineContext->markdownFootnoteLabels = [];
            $this->inlineContext->emptyDestinationLabels = [];
            $this->inlineContext->complexReferenceTargets = [];
        }
        $this->context->hardReferenceSeparators = [];
        $this->context->commentListBoundaries = [];
        $commentBoundary = CommentListBoundary::find($lines);
        $authoredDefinitions = [];
        $invalidDefinitionPrefixes = [];
        $referenceChunk = null;
        $this->context->movedDefinitions = [];
        $this->context->movedFootnotes = [];
        $this->context->movedFootnoteBlockBodies = [];
        $this->context->movedFootnoteSourceLines = [];
        $title = '("(?:[^"\\\\\n]|\\\\.)*"|\'(?:[^\'\\\\\n]|\\\\.)*\'|\((?:[^()\\\\\n]|\\\\.)*\))';
        $quote = '/^((?: {0,3}>[ \t]?)*)/';
        $defined = $this->context->importingFootnoteBody ? $this->inlineContext->definedReferenceLabels : [];
        $labels = $this->context->importingFootnoteBody ? $this->inlineContext->referenceDefinitionLabels : [];
        $kept = [];
        $bareReferenceHeads = [];
        $fence = null;
        $htmlCloser = null;
        $blockDepth = 0;
        $blockList = 0;
        $diagnosticHtml = null;
        $canStart = true;
        $depth = 0;
        $listIndent = 0;
        $itemDepth = 0;
        // The content column every container prefix on the line put the body at.
        $blockIndent = 0;
        $count = count($lines);
        $outdent = 0;
        $listWork = new ReferenceListWork($lines);
        for ($i = 0; $i < $count; $i++) {
            // A definition that closed an item's fence closed the item too, so
            // what the item held below it stands at the top level now.
            if ($outdent > 0 && trim($lines[$i]) !== '') {
                $referenceChunk = null;
                if (strspn($lines[$i], ' ') < $outdent) {
                    $outdent = 0;
                } else {
                    $lines[$i] = substr($lines[$i], $outdent);
                }
            }
            $line = $lines[$i];
            $prefix = preg_match($quote, $line, $parts) === 1 ? $parts[1] : '';
            $content = substr($line, strlen($prefix));
            $quotePrefix = $prefix;
            $this->writeCommentListBoundary($kept, $i, $quotePrefix, $commentBoundary);
            $lineDepth = substr_count($prefix, '>');
            if ($diagnosticHtml !== null) {
                if (
                    trim($content) === '' || $lineDepth < $diagnosticHtml['depth']
                    || ($diagnosticHtml['list'] > 0 && $lineDepth === 0 && strspn($line, ' ') < $diagnosticHtml['list'])
                ) {
                    $diagnosticHtml = null;
                } else {
                    $this->context->markdownHtmlSourceLines[$i] = true;
                    if ($blockHtml !== null) {
                        $blockHtml[$i] = true;
                    }
                }
            }
            $opensItem = false;
            $fenceCloser = null;
            // Leaving the container ends a fence or HTML block opened in it.
            if (
                ($fence !== null || $htmlCloser !== null)
                && ($lineDepth < $blockDepth || ($blockList > 0 && $quotePrefix === '' && trim($line) !== '' && strspn($line, ' ') < $blockList))
            ) {
                // The line that ends the item ends the fence with it. Where the
                // line then moves out of the body, the fence is left with
                // nothing to close it, so its closer is written here.
                if ($fence !== null && $blockList > 0 && $quotePrefix === '') {
                    $fenceCloser = str_repeat(' ', $blockList) . $fence;
                }
                $fence = null;
                $htmlCloser = null;
                $canStart = true;
            }
            if ($htmlCloser !== null) {
                $this->context->markdownHtmlSourceLines[$i] = true;
                if ($blockHtml !== null) {
                    $blockHtml[$i] = true;
                }
                if (preg_match($htmlCloser, $line) === 1) {
                    $htmlCloser = null;
                    $canStart = true;
                }
                $this->context->markdownSourceLines[count($kept)] = $i;
                $kept[] = $line;

                continue;
            }
            if ($fence === null && preg_match('/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/', $content) !== 1 && preg_match('/^ {0,3}(?:[-*+]|[0-9]{1,9}[.)])[ \t]+(?=\S)/', $content, $marker) === 1) {
                $prefix .= $marker[0];
                $content = substr($content, strlen($marker[0]));
                $listIndent = strlen($prefix);
                $opensItem = true;
                $itemDepth = $lineDepth;
            } elseif ($listIndent > 0 && trim($content) !== '') {
                if (strspn($line, ' ') >= $listIndent) {
                    $content = substr($line, $listIndent);
                } else {
                    // Leaving the item closes the quotes it held, so a quote
                    // marker here opens a new quote rather than continuing one.
                    $listIndent = 0;
                    $depth = min($depth, $itemDepth);
                }
            }
            $itemOpener = $opensItem;
            $this->takeItemQuote($line, $content, $prefix, $quotePrefix, $lineDepth, $opensItem, $listIndent, $fence);
            // CONTAINER PREFIXES NEST, and the BLOCK decisions below need the
            // innermost content. An item's indentation or marker came off the
            // line without the quote regex running again, so a block quote
            // inside that item still has its `>` run on the content and a fence
            // or an HTML block opening there would be read as the block's own
            // text. Only the fence and HTML-block readings take the nested run
            // off (markup-carve/carve#2850); a quote inside an item already
            // came off above.
            // Every container prefix the line carries comes off, not just the
            // first: `- - ::: note` opens TWO items on one line, and a quote
            // inside an item carries both prefixes.
            //
            // A LIST MARKER INSIDE AN OPEN FENCE IS VERBATIM CONTENT, so only
            // the quote prefix keeps coming off there - stripping a marker
            // would let a `- ```` line inside the fence read as its closer.
            [$blockContent, $blockIndent] = $this->referenceBlockContent(
                $line,
                $content,
                $quote,
                $listIndent,
                $blockIndent,
                $itemOpener,
                $fence !== null,
            );
            if ($fence !== null) {
                if (preg_match('/^ {0,3}(`{3,}|~{3,})[ \t]*$/', $blockContent, $close) === 1 && $close[1][0] === $fence[0] && strlen($close[1]) >= strlen($fence)) {
                    $fence = null;
                    $canStart = true;
                }
                $this->context->markdownSourceLines[count($kept)] = $i;
                $kept[] = $line;
                $depth = $lineDepth;

                continue;
            }
            [$referenceListAbove, $referenceNestedListAbove] = $this->trackReferenceListContext(
                $listWork,
                $line,
                $quotePrefix,
                $lineDepth,
                $canStart,
                $itemOpener,
                $i,
            );
            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $blockContent, $open) === 1) {
                $fence = $open[1];
                $blockDepth = $lineDepth;
                $blockList = $listIndent;
                $this->context->markdownSourceLines[count($kept)] = $i;
                $kept[] = $line;
                $depth = $lineDepth;

                continue;
            }
            if (
                $blockHtml !== null && $opensItem && strspn($blockContent, ' ') <= 3
                && preg_match('/^\[[ xX]\][ \t]+<!--/', ltrim($blockContent, ' ')) === 1
            ) {
                $blockHtml[$i] = true;
            }
            if (($this->htmlBlockInterrupts)(ltrim($blockContent, ' ')) && strspn($blockContent, ' ') <= 3) {
                $this->context->markdownHtmlSourceLines[$i] = true;
                if ($blockHtml !== null) {
                    $blockHtml[$i] = true;
                }
                $diagnosticHtml = ['depth' => $lineDepth, 'list' => $listIndent];
            }
            $closer = ($this->htmlBlockCloser)(ltrim($blockContent, ' '));
            if ($closer !== null && strspn($blockContent, ' ') <= 3) {
                $this->context->markdownHtmlSourceLines[$i] = true;
                if ($blockHtml !== null) {
                    $blockHtml[$i] = true;
                }
                if (preg_match($closer, substr(ltrim($blockContent, ' '), 2)) !== 1) {
                    $htmlCloser = $closer;
                    $blockDepth = $lineDepth;
                    $blockList = $listIndent;
                }
                $this->context->markdownSourceLines[count($kept)] = $i;
                $kept[] = $line;
                $depth = $lineDepth;
                $canStart = true;

                continue;
            }
            if (preg_match('/^ {0,3}\[\^((?:[^[\]\\\\]|\\\\.)+)\]:/', $content, $footnoteHead) === 1) {
                $this->inlineContext->markdownFootnoteLabels[] = $footnoteHead[1];
            }
            $footnoteStart = $i;
            if (
                $prefix === ''
                && $listIndent === 0
                && preg_match('/^ {0,3}\[\^(?:[^[\]\\\\]|\\\\.)+\]:/', $content) === 1
                && $this->collectFootnoteDefinition($lines, $i)
            ) {
                if ($fenceCloser !== null) {
                    $this->context->markdownSourceLines[count($kept)] = $footnoteStart;
                    $kept[] = $fenceCloser;
                    $outdent = strspn($fenceCloser, ' ');
                }
                $footnoteIndent = strspn($line, ' ');
                while (($listWork->columns[$lineDepth] ?? []) !== [] && end($listWork->columns[$lineDepth]) > $footnoteIndent) {
                    array_pop($listWork->columns[$lineDepth]);
                }
                $listWork->definitionAbove[$lineDepth] = $referenceListAbove;
                if ($this->partsTwoLists($lines, $i, $kept, $quotePrefix, $listWork->columns[$lineDepth] ?? [], $referenceListAbove)) {
                    for ($blank = 0; $blank < 2; $blank++) {
                        $this->context->hardReferenceSeparators[count($kept)] = true;
                        $this->context->markdownSourceLines[count($kept)] = $i;
                        $kept[] = $quotePrefix;
                    }
                }
                $depth = 0;

                continue;
            }
            $line = $this->escapeEscapedLabelCloser($line, $content, $opensItem, $lineDepth + $listIndent);
            if ($canStart && !$opensItem && $lineDepth === 0 && $listIndent === 0 && preg_match('/^ {0,3}\[(?!\^)/', $content) === 1) {
                if ($referenceChunk === null || $i > $referenceChunk['through']) {
                    $chunk = [$content];
                    $offsets = [$i => 0];
                    $size = strlen($content) + 1;
                    for ($next = $i + 1; $next < $count && trim($lines[$next]) !== ''; $next++) {
                        $candidate = $lines[$next];
                        if (
                            preg_match('/^ {0,3}(?:>|#{1,6}(?:[ \t]|$)|(?:[-*+]|0{0,8}1[.)])[ \t]+\S|=+[ \t]*$|`{3,}|~{3,})/', $candidate) === 1
                            || preg_match(self::THEMATIC_BREAK, $candidate) === 1
                            || (($this->indentWidth)($candidate) < 4 && ($this->htmlBlockInterrupts)(ltrim($candidate, " \t")))
                        ) {
                            break;
                        }
                        $offsets[$next] = $size;
                        $size += strlen($candidate) + 1;
                        $chunk[] = $candidate;
                    }
                    $referenceChunk = ['through' => $next - 1, 'text' => implode("\n", $chunk), 'offsets' => $offsets];
                }
                $parsed = ReferenceDefinition::read($referenceChunk['text'], $referenceChunk['offsets'][$i]);
                if ($parsed !== null && str_starts_with($parsed['target'], '<>') && str_contains($parsed['target'], "\n")) {
                    if (str_contains($parsed['label'], "\n")) {
                        $rawKey = $this->inlineStage->normalizeReferenceLabel($parsed['label']);
                        $this->inlineContext->emptyDestinationLabels[$rawKey] = ['title' => '', 'hasTitle' => false];
                        $authoredDefinitions[$rawKey] = true;
                        $defined[$rawKey] = true;
                        $this->context->boundaryDiagnostics[] = new MigrationDiagnostic(
                            'structure-unspellable',
                            'An empty reference definition with a multiline label cannot be retained.',
                            'warning',
                            'dropped',
                            'exact',
                            'line:' . ($i + 1),
                        );
                        $i += $parsed['lines'] - 1;
                        $referenceChunk = null;
                        $canStart = true;

                        continue;
                    }
                    if (preg_match('/^<>\s+("(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|\((?:\\\\.|[^()\\\\])*\))\s*\z/', $parsed['target'], $titled) === 1) {
                        // A Carve definition has no title slot, so uses become titled inline links.
                        $rawKey = $this->inlineStage->normalizeReferenceLabel($parsed['label']);
                        if (!isset($defined[$rawKey])) {
                            $this->inlineContext->emptyDestinationLabels[$rawKey] = ['title' => $this->inlineStage->decodeLinkTitle(substr($titled[1], 1, -1)), 'hasTitle' => true];
                            $this->context->boundaryDiagnostics[] = new MigrationDiagnostic(
                                'structure-unspellable',
                                'Dropped a titled empty reference definition; uses become inline links.',
                                'warning',
                                'dropped',
                                'exact',
                                'line:' . ($i + 1),
                            );
                        }
                        $authoredDefinitions[$rawKey] = true;
                        $defined[$rawKey] = true;
                        $i += $parsed['lines'] - 1;
                        $this->dropDefinitionLine($lines, $i, '', $kept);
                        $referenceChunk = null;
                        $canStart = true;

                        continue;
                    }
                    $line = $content = '[' . $parsed['label'] . ']: <>';
                    $lines[$i] = $line;
                    for ($part = 1; $part < $parsed['lines']; $part++) {
                        $lines[$i + $part] = '';
                    }
                    $referenceChunk = null;
                    $parsed = ['label' => $parsed['label'], 'target' => '<>', 'lines' => 1, 'complex' => false];
                }
                if ($parsed !== null && $parsed['complex'] && !str_starts_with($parsed['target'], '<>')) {
                    $rawKey = $this->inlineStage->normalizeReferenceLabel($parsed['label']);
                    if (!isset($authoredDefinitions[$rawKey])) {
                        $this->inlineContext->complexReferenceTargets[$rawKey] = $parsed['target'];
                        $authoredDefinitions[$rawKey] = true;
                        $defined[$rawKey] = true;
                    }
                    $i += $parsed['lines'] - 1;
                    $listWork->definitionEnd = $i;
                    $listWork->definitionAbove[$lineDepth] = $referenceListAbove;
                    if ($fenceCloser !== null) {
                        $this->context->markdownSourceLines[count($kept)] = $i;
                        $kept[] = $fenceCloser;
                        $outdent = strspn($fenceCloser, ' ');
                    }
                    if ($this->partsTwoLists($lines, $i, $kept, $quotePrefix, $listWork->columns[$lineDepth] ?? [], $referenceListAbove)) {
                        for ($blank = 0; $blank < 2; $blank++) {
                            $this->context->hardReferenceSeparators[count($kept)] = true;
                            $this->context->markdownSourceLines[count($kept)] = $i;
                            $kept[] = $quotePrefix;
                        }
                    }
                    $depth = 0;
                    $canStart = true;

                    continue;
                }
                if (
                    $parsed === null
                    && $this->opensNoMarkdownLabel($content)
                ) {
                    preg_match('/^ {0,3}\[((?:[^\[\]\\\\]|\\\\.)+)\]:/', $content, $invalidLabel);
                    $invalidDefinitionPrefixes[count($kept)] = isset($invalidLabel[1]) ? $this->inlineStage->normalizeReferenceLabel($invalidLabel[1]) : null;
                    $this->context->markdownSourceLines[count($kept)] = $i;
                    $kept[] = $line;
                    $canStart = false;
                    unset($listWork->definitionAbove[$lineDepth]);

                    continue;
                }
            }
            if (
                $lineDepth > 0 && $listIndent === 0 && ($canStart || $lineDepth > $depth)
                && preg_match('/^ {0,3}\[(?!\^)/', $content) === 1
            ) {
                $quoted = $this->readQuotedDefinition($lines, $i, $lineDepth, $content);
                if ($quoted !== null) {
                    // A Carve definition has no title slot, so uses become titled inline links.
                    $rawKey = $this->inlineStage->normalizeReferenceLabel($quoted['label']);
                    if ($quoted['target'] !== null) {
                        if (!isset($authoredDefinitions[$rawKey])) {
                            $this->inlineContext->complexReferenceTargets[$rawKey] = $quoted['target'];
                        }
                    } elseif (!isset($defined[$rawKey])) {
                        $this->inlineContext->emptyDestinationLabels[$rawKey] = ['title' => $quoted['title'], 'hasTitle' => true];
                        $this->context->boundaryDiagnostics[] = new MigrationDiagnostic(
                            'structure-unspellable',
                            'Dropped a titled empty reference definition; uses become inline links.',
                            'warning',
                            'dropped',
                            'exact',
                            'line:' . ($i + 1),
                        );
                    }
                    $authoredDefinitions[$rawKey] = true;
                    $defined[$rawKey] = true;
                    $depth = $lineDepth;
                    $i += $quoted['lines'] - 1;
                    $listWork->definitionEnd = $i;
                    $this->holdParagraphContinuation($lines, $i, $quotePrefix, 0);
                    $canStart = !$this->dropDefinitionLine($lines, $i, $quotePrefix, $kept);

                    continue;
                }
            }
            // A deeper quote opens a block; a shallower line is lazy continuation.
            if (
                ($canStart || $opensItem || $lineDepth > $depth)
                && preg_match('/^ {0,3}\[((?:[^[\]\\\\]|\\\\.)+)\]:(.*)$/', $content, $definition) === 1
                && preg_match('/^[ \t]*(?:(?:<(?:[^<>\n\\\\]|\\\\.)*>|[^<\s]\S*)(?:[ \t]+' . $title . ')?[ \t]*)?$/', $definition[2]) === 1
            ) {
                $depth = $lineDepth;
                $key = $this->inlineStage->normalizeReferenceLabel($this->inlineStage->decodeLinkTitle($definition[1]));
                $continued = [];
                if (
                    trim($definition[2]) === ''
                    && $i + 1 < $count
                    && str_starts_with($lines[$i + 1], $quotePrefix)
                    && !$this->opensMarkdownBlock(substr($lines[$i + 1], strlen($quotePrefix)))
                    && preg_match('/^[ \t]*(?:<(?:[^<>\n\\\\]|\\\\.)*>|[^<\s]\S*)(?:[ \t]+' . $title . ')?[ \t]*$/', substr($lines[$i + 1], strlen($quotePrefix))) === 1
                ) {
                    $continued[] = $lines[++$i];
                    $definition[2] = substr($continued[0], strlen($quotePrefix));
                }
                if (preg_match('/^[ \t]*<>(?:[ \t]+' . $title . ')?[ \t]*$/', $definition[2], $empty) !== 1) {
                    $target = trim($definition[2]);
                    // One with no destination is no definition at all, but paragraph text.
                    if (
                        $target === ''
                        || (!str_starts_with($definition[1], '^') && ReferenceDefinition::read('[' . $definition[1] . ']: ' . $target, 0) === null)
                    ) {
                        $continuedCount = count($continued);
                        for ($at = 0; $at <= $continuedCount; $at++) {
                            $this->context->markdownSourceLines[count($kept) + $at] = $i - $continuedCount + $at;
                        }
                        if ($target === '') {
                            $marker = strpos($line, '[' . $definition[1] . ']:');
                            if ($marker !== false) {
                                $bareReferenceHeads[count($kept)] = [$this->inlineStage->normalizeReferenceLabel($definition[1]), $marker, strlen($definition[1])];
                            }
                        }
                        array_push($kept, $line, ...$continued);
                        $canStart = false;
                        unset($listWork->definitionAbove[$lineDepth]);

                        continue;
                    }
                    $canStart = true;
                    $rawKey = $this->inlineStage->normalizeReferenceLabel($definition[1]);
                    $repeated = isset($labels[$key]) || isset($authoredDefinitions[$rawKey]);
                    $authoredDefinitions[$rawKey] = true;
                    if (!$repeated) {
                        $labels[$key] = $definition[1];
                    }
                    $defined[$key] = true;
                    if (str_starts_with($definition[1], '^')) {
                        $this->inlineContext->markdownFootnoteLabels[] = substr($definition[1], 1);
                    }
                    if (
                        !str_starts_with($definition[1], '^')
                        && preg_match('/[ \t]' . $title . '$/', $target) !== 1
                        && $i + 1 < $count
                        && str_starts_with($lines[$i + 1], $quotePrefix)
                        && preg_match('/^[ \t]*' . $title . '[ \t]*$/', substr($lines[$i + 1], strlen($quotePrefix)), $next) === 1
                    ) {
                        $target .= ' ' . $next[1];
                        $continued[] = $lines[++$i];
                    }
                    if (!$repeated && !str_starts_with($definition[1], '^') && strpbrk($definition[1], '|<&') !== false) {
                        $this->inlineContext->complexReferenceTargets[$rawKey] = $target;
                    }
                    $listWork->definitionEnd = $i;
                    $listWork->definitionAbove[$lineDepth] = $referenceListAbove;
                    $marker = substr($prefix, strlen($quotePrefix));
                    // A footnote is not a reference definition, so it stays put. So
                    // does one on a nested item's marker line, where fmt writes it,
                    // and one that alone keeps two lists apart.
                    if (
                        str_starts_with($definition[1], '^')
                        || ($opensItem && strspn($marker, ' ') >= 2)
                        || (!$opensItem && $this->partsTwoLists($lines, $i, $kept, $quotePrefix, $listWork->columns[$lineDepth] ?? [], $referenceListAbove))
                        || (!$opensItem && $referenceNestedListAbove && $this->partsTwoLists($lines, $i, $kept, $quotePrefix, null, $referenceListAbove))
                    ) {
                        if (!str_starts_with($definition[1], '^')) {
                            $this->writeHardListBoundary($opensItem, $line, $kept, $i, $quotePrefix, $fenceCloser, $outdent);
                            $this->context->markdownSourceLines[count($kept)] = $i - count($continued);
                            $head = strpos($line, '[' . $definition[1] . ']:');
                            $kept[] = $continued === [] || $head === false
                                ? $line : substr($line, 0, $head) . '[' . $definition[1] . ']: ' . $target;

                            continue;
                        }
                        $continuedCount = count($continued);
                        for ($at = 0; $at <= $continuedCount; $at++) {
                            $this->context->markdownSourceLines[count($kept) + $at] = $i - $continuedCount + $at;
                        }
                        array_push($kept, $line, ...$continued);

                        continue;
                    }
                    // The first definition of a label wins, so a later one says nothing.
                    if (!$repeated && (strpbrk($definition[1], '|<&') !== false)) {
                        $this->inlineContext->complexReferenceTargets[$rawKey] = $target;
                    }
                    if (!$repeated) {
                        $this->context->movedDefinitions[] = '[' . $definition[1] . ']: ' . $target;
                    }
                    if ($fenceCloser !== null) {
                        $kept[] = $fenceCloser;
                        $outdent = strspn($fenceCloser, ' ');
                    }
                    if ($opensItem) {
                        $this->emptyDefinitionItem($lines, $i, $prefix, $quotePrefix, $kept);

                        continue;
                    }
                    $this->holdParagraphContinuation($lines, $i, $quotePrefix, $listIndent);
                    if ($this->dropDefinitionLine($lines, $i, $quotePrefix, $kept, $listIndent)) {
                        $canStart = false;
                    }

                    continue;
                }
                $raw = $empty[1] ?? '';
                if (
                    $raw === ''
                    && $i + 1 < $count
                    && str_starts_with($lines[$i + 1], $quotePrefix)
                    && preg_match('/^[ \t]*' . $title . '[ \t]*$/', substr($lines[$i + 1], strlen($quotePrefix)), $next) === 1
                ) {
                    $raw = $next[1];
                    $i++;
                }
                if (!isset($defined[$key])) {
                    $canonicalLabel = str_contains($definition[1], '\\')
                        ? $definition[1] : $this->inlineStage->decodeLinkTitle($definition[1]);
                    $labels[$key] = $canonicalLabel;
                    if ($raw === '') {
                        if (str_starts_with($canonicalLabel, '^') || str_starts_with($canonicalLabel, '@') || str_contains($canonicalLabel, '\\')) {
                            $this->inlineContext->emptyDestinationLabels[$key] = ['title' => '', 'hasTitle' => false];
                            $this->context->boundaryDiagnostics[] = new MigrationDiagnostic(
                                'structure-unspellable',
                                'An empty reference definition with this label cannot be retained.',
                                'warning',
                                'dropped',
                                'exact',
                                'line:' . ($i + 1),
                            );
                        } else {
                            $this->context->movedDefinitions[] = '[' . $canonicalLabel . ']:';
                        }
                    } else {
                        $this->inlineContext->emptyDestinationLabels[$key] = ['title' => $this->inlineStage->decodeLinkTitle(substr($raw, 1, -1)), 'hasTitle' => true];
                        $this->context->boundaryDiagnostics[] = new MigrationDiagnostic(
                            'structure-unspellable',
                            'Dropped a titled empty reference definition; uses become inline links.',
                            'warning',
                            'dropped',
                            'exact',
                            'line:' . ($i + 1),
                        );
                    }
                }
                $defined[$key] = true;
                $listWork->definitionEnd = $i;
                $listWork->definitionAbove[$lineDepth] = $referenceListAbove;
                $canStart = true;
                if ($fenceCloser !== null) {
                    $this->context->markdownSourceLines[count($kept)] = $i;
                    $kept[] = $fenceCloser;
                    if (!$opensItem) {
                        $outdent = strspn($fenceCloser, ' ');
                    }
                }
                $definitionColumn = strpos($line, '[' . $definition[1] . ']:');
                if (
                    !$opensItem && $definitionColumn !== false
                    && trim(substr($line, strlen($quotePrefix), $definitionColumn - strlen($quotePrefix))) === ''
                    && $this->partsTwoLists($lines, $i, $kept, $quotePrefix, $listWork->columns[$lineDepth] ?? [], $referenceListAbove)
                ) {
                    for ($blank = 0; $blank < 2; $blank++) {
                        $this->context->hardReferenceSeparators[count($kept)] = true;
                        $this->context->markdownSourceLines[count($kept)] = $i;
                        $kept[] = $quotePrefix;
                    }

                    continue;
                }
                if ($opensItem) {
                    $this->emptyDefinitionItem($lines, $i, $prefix, $quotePrefix, $kept);

                    continue;
                }
                $this->holdParagraphContinuation($lines, $i, $quotePrefix, $listIndent);
                if ($this->dropDefinitionLine($lines, $i, $quotePrefix, $kept, $listIndent)) {
                    $canStart = false;
                }

                continue;
            }
            if ($paragraphLines !== null && !$canStart && !$opensItem && preg_match('/^[ \t]*&#(?:43|45);(?=[ \t])/', $blockContent) === 1) {
                $paragraphLines[$i] = true;
            }
            $this->context->markdownSourceLines[count($kept)] = $i;
            $kept[] = $line;
            if (trim($content) !== '') {
                unset($listWork->definitionAbove[$lineDepth]);
            }
            if ($canStart || $lineDepth >= $depth || trim($content) === '') {
                $depth = $lineDepth;
            }
            $canStart = trim($content) === ''
                || preg_match('/^ {0,3}(?:#{1,6}(?:[ \t]|$)|([-*_])(?:[ \t]*\1){2,}[ \t]*$|=+[ \t]*$)/', $content) === 1;
        }

        foreach ($invalidDefinitionPrefixes as $index => $label) {
            if ($label === null || !isset($defined[$label])) {
                $kept[$index] = preg_replace('/^( *)\[/', '$1\\\\[', $kept[$index]) ?? $kept[$index];
            } else {
                $kept[$index] = preg_replace('/^( {0,3}\[(?:[^\[\]\\\\]|\\\\.)+\])(?=:)/', '$1[]', $kept[$index]) ?? $kept[$index];
            }
        }

        $kept = $this->resolveBareReferenceHeads($kept, $bareReferenceHeads, $authoredDefinitions);

        $this->inlineContext->definedReferenceLabels = $defined;
        $this->inlineContext->referenceDefinitionLabels = $labels;
        $this->inlineContext->authoredReferenceLabelKeys = $authoredDefinitions;

        return $kept;
    }

    /**
     * A quote inside an item: its markers are a container prefix too, so a
     * definition opening the quote's content is read as one. On the item's
     * marker line the quote is the item's first block, so the line opens no
     * definition item.
     */
    private function takeItemQuote(
        string $line,
        string &$content,
        string &$prefix,
        string &$quotePrefix,
        int &$lineDepth,
        bool &$opensItem,
        int $listIndent,
        ?string $fence,
    ): void {
        if ($listIndent === 0 || $fence !== null || preg_match('/^((?: {0,3}>[ \t]?)+)/', $content, $nested) !== 1) {
            return;
        }
        $quotePrefix = $prefix = substr($line, 0, strlen($line) - strlen($content)) . $nested[1];
        $content = (string)substr($content, strlen($nested[1]));
        $lineDepth = substr_count($quotePrefix, '>');
        $opensItem = false;
    }

    /**
     * The definition took the head of a paragraph, so a line under it indented
     * four columns continues that paragraph rather than opening code. It moves
     * to the container's column, escaped where it would open a block there.
     *
     * @param array<int, string> $lines
     * @param int $index
     * @param string $quotePrefix
     * @param int $listIndent
     */
    private function holdParagraphContinuation(array &$lines, int $index, string $quotePrefix, int $listIndent): void
    {
        $next = $lines[$index + 1] ?? null;
        if ($listIndent > 0 || $next === null || $this->quotePrefixOf($next) !== $quotePrefix) {
            return;
        }
        $rest = substr($next, strlen($quotePrefix));
        $text = ltrim($rest, " \t");
        // Raw HTML would open an HTML block there and has no escape that keeps it HTML.
        if ($text === '' || ($this->indentWidth)($rest) < 4 || str_starts_with($text, '<')) {
            return;
        }
        if (preg_match('/^\d{1,9}(?=[.)](?:[ \t]|$))/', $text, $digits) === 1) {
            $text = $digits[0] . '\\' . substr($text, strlen($digits[0]));
        } elseif ($this->opensMarkdownBlock($text)) {
            $text = '\\' . $text;
        }
        $lines[$index + 1] = $quotePrefix . $text;
    }

    /**
     * A definition-shaped line Markdown reads as text but Carve would read as a
     * definition.
     */
    private function opensNoMarkdownLabel(string $content): bool
    {
        return preg_match('/^ {0,3}\[(?:[^[\]\\\\\n]|\\\\.)+\]:[ \t]*</', $content) === 1
            || preg_match('/^ {0,3}\[(?:[^\]\\\\\n]|\\\\.)*\[(?:[^\]\\\\\n]|\\\\.)*\]:/', $content) === 1
            // An escaped `]` closes no Markdown label, but Carve reads one there.
            || preg_match(self::ESCAPED_LABEL_CLOSER, $content) === 1;
    }

    /**
     * Escape a contained line whose only label closer is escaped, so Carve
     * does not read a definition Markdown has not.
     */
    private function escapeEscapedLabelCloser(string $line, string $content, bool $opensItem, int $nesting): string
    {
        if ((!$opensItem && $nesting === 0) || preg_match(self::ESCAPED_LABEL_CLOSER, $content) !== 1) {
            return $line;
        }

        return preg_replace('/^([ \t]*(?:>[ \t]?)*[ \t]*(?:(?:[-*+]|\d{1,9}[.)])[ \t]+)?)\[/', '$1\\\\[', $line) ?? $line;
    }

    /**
     * Three blank lines, at least one of them hard, between a list-ending comment and the next list.
     *
     * @param array<string> $kept
     * @param int $index
     * @param string $quotePrefix
     * @param array<int, int> $boundaries
     */
    private function writeCommentListBoundary(array &$kept, int $index, string $quotePrefix, array $boundaries): void
    {
        if (!isset($boundaries[$index])) {
            return;
        }
        $blanks = 0;
        for ($at = count($kept) - 1; $at >= 0 && trim(str_replace('>', '', $kept[$at])) === ''; $at--) {
            $blanks++;
        }
        for ($blank = $blanks; $blank < 3 || $blank === $blanks; $blank++) {
            $this->context->hardReferenceSeparators[count($kept)] = true;
            $this->context->commentListBoundaries[count($kept)] = $boundaries[$index];
            $this->context->markdownSourceLines[count($kept)] = $index;
            $kept[] = rtrim($quotePrefix);
        }
    }

    /**
     * An invisible line no longer parts two lists (carve#4716), so a hard boundary does.
     *
     * @param bool $opensItem
     * @param string $line
     * @param array<string> $kept
     * @param int $index
     * @param string $quotePrefix
     * @param string|null $fenceCloser
     * @param int $outdent
     */
    private function writeHardListBoundary(
        bool $opensItem,
        string $line,
        array &$kept,
        int $index,
        string $quotePrefix,
        ?string &$fenceCloser,
        int &$outdent,
    ): void {
        if ($opensItem || strpos($line, '[') !== strlen($quotePrefix)) {
            return;
        }
        if ($fenceCloser !== null) {
            $this->context->markdownSourceLines[count($kept)] = $index;
            $kept[] = $fenceCloser;
            $outdent = strspn($fenceCloser, ' ');
            $fenceCloser = null;
        }
        for ($blank = 0; $blank < 2; $blank++) {
            $this->context->hardReferenceSeparators[count($kept)] = true;
            $this->context->markdownSourceLines[count($kept)] = $index;
            $kept[] = $quotePrefix;
        }
    }

    /**
     * @return array{string, int}
     */
    private function referenceBlockContent(
        string $line,
        string $content,
        string $quote,
        int $listIndent,
        int $blockIndent,
        bool $opensItem,
        bool $insideFence,
    ): array {
        $blockContent = $insideFence && strspn($line, ' ') >= $blockIndent
            ? substr($line, $blockIndent)
            : $content;
        while ($listIndent > 0) {
            if (preg_match($quote, $blockContent, $nested) === 1 && $nested[1] !== '') {
                $blockContent = substr($blockContent, strlen($nested[1]));

                continue;
            }
            if ($insideFence) {
                break;
            }
            // `- - -` is a thematic break, not two nested items.
            if (
                preg_match('/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/', $blockContent) === 1
                || preg_match('/^ {0,3}(?:[-*+]|[0-9]{1,9}[.)])[ \t]+(?=\S)/', $blockContent, $inner) !== 1
            ) {
                break;
            }
            $blockContent = substr($blockContent, strlen($inner[0]));
        }
        // Count every prefix, including nested list markers, for the body's column.
        if ($opensItem) {
            $blockIndent = strlen($line) - strlen($blockContent);
        } elseif ($listIndent === 0) {
            $blockIndent = 0;
        }
        if (!$opensItem && $blockIndent > $listIndent && strspn($line, ' ') >= $blockIndent) {
            $blockContent = substr($line, $blockIndent);
            if (preg_match($quote, $blockContent, $nested) === 1 && $nested[1] !== '') {
                $blockContent = substr($blockContent, strlen($nested[1]));
            }
        }

        return [$blockContent, $blockIndent];
    }

    /**
     * @param array<string> $kept
     * @param array<int, array{string, int, int}> $bareReferenceHeads
     * @param array<string, true> $authoredDefinitions
     *
     * @return array<string>
     */
    private function resolveBareReferenceHeads(array $kept, array $bareReferenceHeads, array $authoredDefinitions): array
    {
        foreach ($bareReferenceHeads as $index => [$rawLabel, $marker, $labelLength]) {
            if (isset($authoredDefinitions[$rawLabel])) {
                $kept[$index] = substr_replace($kept[$index], '[]', $marker + $labelLength + 2, 0);
            } else {
                $kept[$index] = substr_replace($kept[$index], '\\', $marker, 0);
            }
        }

        return $kept;
    }

    /**
     * @param \MarkupCarve\Carve\Converter\Markdown\ReferenceListWork $work
     * @param string $line
     * @param string $quotePrefix
     * @param int $lineDepth
     * @param bool $canStart
     * @param bool $opensItem
     * @param int $i
     *
     * @return array{bool, bool}
     */
    private function trackReferenceListContext(
        ReferenceListWork $work,
        string $line,
        string $quotePrefix,
        int $lineDepth,
        bool $canStart,
        bool $opensItem,
        int $i,
    ): array {
        if ($work->track && $lineDepth > 0) {
            preg_match_all('/ {0,3}>[ \t]?/', $quotePrefix, $referenceQuotes);
            $physicalColumn = 0;
            $semanticColumn = 0;
            foreach ($referenceQuotes[0] as $parentDepth => $referenceQuote) {
                $markerColumn = $physicalColumn + strspn($referenceQuote, ' ');
                $parentColumns = $work->columns[$parentDepth] ?? [];
                while ($parentColumns !== [] && end($parentColumns) > $markerColumn - $semanticColumn) {
                    array_pop($parentColumns);
                }
                $work->columns[$parentDepth] = $parentColumns;
                if ($parentColumns === []) {
                    unset($work->definitionAbove[$parentDepth], $work->emptyColumns[$parentDepth], $work->closedEmptyLists[$parentDepth]);
                }
                $physicalColumn = $physicalColumn - $physicalColumn % 4
                    + ($this->columnWidth)(str_repeat(' ', $physicalColumn % 4) . $referenceQuote);
                $semanticColumn = str_ends_with($referenceQuote, "\t") ? $markerColumn + 2 : $physicalColumn;
            }
        }
        $referenceBody = substr($line, strlen($quotePrefix));
        $referenceNestedListAbove = false;
        $referenceContinuesDefinition = $work->track && $work->definitionEnd === $i - 1
            && trim($referenceBody) !== '' && !$this->opensMarkdownBlock($referenceBody);
        $referenceListAbove = ($work->columns[$lineDepth] ?? []) !== [] || isset($work->closedEmptyLists[$lineDepth])
            || ($work->definitionAbove[$lineDepth] ?? false);
        if (trim($referenceBody) !== '' && preg_match('/^[ \t]*\[.*\]:/', $referenceBody) !== 1) {
            unset($work->definitionAbove[$lineDepth]);
        }
        $referenceLazyQuote = $work->track && $lineDepth < $work->quoteDepth && !$canStart
            && trim($referenceBody) !== '' && !$this->opensMarkdownBlock($referenceBody);
        if ($work->track && !$referenceLazyQuote) {
            foreach (array_keys($work->columns) as $quoteDepth) {
                if ($quoteDepth > $lineDepth) {
                    unset($work->columns[$quoteDepth], $work->definitionAbove[$quoteDepth], $work->emptyColumns[$quoteDepth], $work->closedEmptyLists[$quoteDepth]);
                }
            }
            $physicalQuoteColumn = ($this->columnWidth)($quotePrefix);
            $quoteRemainder = str_ends_with($quotePrefix, "\t")
                ? $physicalQuoteColumn - ($this->columnWidth)(substr($quotePrefix, 0, -1)) - 1 : 0;
            $quoteColumn = $physicalQuoteColumn - $quoteRemainder;
            $referencePadding = substr($referenceBody, 0, strspn($referenceBody, " \t"));
            $referenceIndent = ($this->columnWidth)(str_repeat(' ', $physicalQuoteColumn % 4) . $referencePadding)
                - $physicalQuoteColumn % 4 + $quoteRemainder;
            $columns = $work->columns[$lineDepth] ?? [];
            if (trim($referenceBody) === '' && isset($work->emptyColumns[$lineDepth])) {
                if (count($columns) === $work->emptyColumns[$lineDepth]) {
                    array_pop($columns);
                    $work->closedEmptyLists[$lineDepth] = true;
                }
                unset($work->emptyColumns[$lineDepth]);
            } elseif (trim($referenceBody) !== '') {
                unset($work->closedEmptyLists[$lineDepth], $work->emptyColumns[$lineDepth]);
            }
            if (trim($referenceBody) !== '' && !$referenceContinuesDefinition && ($canStart || $opensItem || $lineDepth !== $work->quoteDepth || $this->opensMarkdownBlock($referenceBody))) {
                while ($columns !== [] && end($columns) > $referenceIndent) {
                    array_pop($columns);
                }
            }
            $markerAt = 0;
            $referenceColumn = $physicalQuoteColumn;
            $referenceLength = strlen($referenceBody);
            $referenceThematic = preg_match(self::THEMATIC_BREAK, preg_replace('/^ {0,3}/', '', $referenceBody) ?? $referenceBody) === 1;
            while (!$referenceThematic && preg_match('/\G([ \t]*)(?:[-*+]|[0-9]{1,9}[.)])([ \t]+|$)/', $referenceBody, $referenceHead, offset: $markerAt) === 1) {
                $tabOffset = $referenceColumn % 4;
                $columnBase = $referenceColumn - $tabOffset;
                $paddingPrefix = str_repeat(' ', $tabOffset);
                $markerIndent = $columnBase + ($this->columnWidth)($paddingPrefix . $referenceHead[1]) - $quoteColumn;
                while ($columns !== [] && end($columns) > $markerIndent) {
                    array_pop($columns);
                }
                if ($markerIndent - ($columns === [] ? 0 : (int)end($columns)) > 3) {
                    break;
                }
                $referencePrefix = $paddingPrefix . $referenceHead[0];
                $markerAt += strlen($referenceHead[0]);
                if ($markerAt >= $referenceLength) {
                    $referencePrefix = rtrim($referencePrefix, " \t") . ' ';
                }
                $columns[] = $columnBase + ($this->itemContentColumn)($referencePrefix) - $quoteColumn;
                $referenceColumn = $columnBase + ($this->columnWidth)($paddingPrefix . $referenceHead[0]);
                if ($markerAt >= $referenceLength) {
                    $work->emptyColumns[$lineDepth] = count($columns);

                    break;
                }
            }
            $referenceNestedListAbove = count($work->columns[$lineDepth] ?? []) > count($columns) && $columns !== [];
            $work->columns[$lineDepth] = $columns;
            $work->quoteDepth = $lineDepth;
        }

        return [$referenceListAbove, $referenceNestedListAbove];
    }

    /**
     * Take a footnote definition out of the body for the end of the document,
     * reporting whether it was taken. `carve fmt` writes footnotes there, ahead
     * of the reference definitions.
     *
     * Paragraph continuations use Carve's two-column prefix. A body with later
     * blocks retains its Markdown prefix until block conversion removes it.
     *
     * @param array<string> $lines
     * @param int $index Advanced past the block taken.
     */
    protected function collectFootnoteDefinition(array $lines, int &$index): bool
    {
        $count = count($lines);
        $end = $index;
        preg_match('/^ {0,3}\[\^(?:[^\]\\\\]|\\\\.)+\]:[ \t]*(.*)$/', $lines[$index], $opening);
        $paragraphOpen = isset($opening[1]) && trim($opening[1]) !== '' && !$this->opensMarkdownBlock($opening[1]);
        for ($at = $index + 1; $at < $count; $at++) {
            $line = ($this->expandLeadingTabs)($lines[$at], 8);
            if (
                !$paragraphOpen
                || trim($line) === ''
                || strspn($line, ' ') >= 8
                || $this->opensMarkdownBlock(ltrim($line, ' '))
                || preg_match('/^ *\[\^(?:[^[\]\\\\]|\\\\.)+\]:/', $line) === 1
            ) {
                break;
            }
            $end = $at;
        }
        // Content past a blank line belongs to the footnote too, and this move
        // would leave it behind.
        for ($at = $end + 1; $at < $count; $at++) {
            if (trim($lines[$at]) !== '') {
                if (strspn(($this->expandLeadingTabs)($lines[$at], 4), ' ') >= 4) {
                    $block = [ltrim($lines[$index], ' ')];
                    for ($next = $index + 1; $next < $count; $next++) {
                        $line = ($this->expandLeadingTabs)($lines[$next], 4);
                        if ($next > $end && trim($line) !== '' && strspn($line, ' ') < 4) {
                            break;
                        }
                        $block[] = trim($line) === '' ? '' : ($next <= $end ? '    ' . ltrim($line, ' ') : $line);
                    }
                    while (end($block) === '') {
                        array_pop($block);
                    }
                    $this->context->movedFootnoteSourceLines[] = $index + $this->context->markdownFrontmatterLines + 1;
                    $this->context->movedFootnoteBlockBodies[count($this->context->movedFootnotes)] = true;
                    $this->context->movedFootnotes[] = $block;
                    $index = $next - 1;

                    return true;
                }

                break;
            }
        }
        preg_match('/^ {0,3}\[\^((?:[^\]\\\\]|\\\\.)+)\]:/', $lines[$index], $definition);
        if (isset($definition[1])) {
            $this->inlineContext->markdownFootnoteLabels[] = $definition[1];
        }
        $block = [ltrim($lines[$index], ' ')];
        if (preg_match('/^ {0,3}\[\^(?:[^\]\\\\]|\\\\.)+\]:[ \t]*$/', $block[0]) === 1 && $end === $index) {
            $block[0] .= " \x00FNEMPTY\x00";
        }
        for ($at = $index + 1; $at <= $end; $at++) {
            // Reference definitions cannot interrupt the Markdown paragraph, but can in Carve.
            $block[] = '  ' . ($this->escapeBlockOpener)(ltrim(($this->expandLeadingTabs)($lines[$at], 8), ' '));
        }
        $this->context->movedFootnoteSourceLines[] = $index + $this->context->markdownFrontmatterLines + 1;
        $this->context->movedFootnotes[] = $block;
        $index = $end;

        return true;
    }

    /**
     * The item whose marker line held the definition ending at `$index`: its
     * next line takes the marker and is read again, or it is written empty,
     * `- %%`, set apart from a following block that would otherwise attach to it.
     *
     * @param array<string> $lines
     * @param int $index
     * @param string $prefix
     * @param string $quotePrefix
     * @param array<string> $kept
     */
    protected function emptyDefinitionItem(array &$lines, int &$index, string $prefix, string $quotePrefix, array &$kept): void
    {
        $marker = substr($prefix, strlen($quotePrefix));
        $next = $lines[$index + 1] ?? null;
        $held = $next !== null && str_starts_with($next, $quotePrefix) ? substr($next, strlen($quotePrefix)) : null;
        // The item goes on past one blank line (a second one ends the emptied
        // item), and its first block below takes the marker: the removed
        // definition leaves no gap to loosen the list.
        if ($next !== null && trim(substr($next, strlen(rtrim($quotePrefix)))) === '' && str_starts_with($next, rtrim($quotePrefix))) {
            $at = $index + 2;
            $body = isset($lines[$at]) && str_starts_with($lines[$at], $quotePrefix) ? substr($lines[$at], strlen($quotePrefix)) : null;
            $indent = $body === null ? 0 : strspn($body, ' ');
            if ($body !== null && $indent >= strlen($marker) && $indent < strlen($marker) + 4 && !str_contains(substr($body, 0, $indent + 1), "\t")) {
                $lines[$at] = $prefix . substr($body, strlen($marker));
                $index = $at - 1;

                return;
            }
        }
        // A lazy line: the paragraph holding the definition is still open.
        $lazy = $next !== null && $held === null && !str_starts_with(ltrim($next, ' '), '>') ? $next : $held;
        if (
            $lazy !== null
            && trim($lazy) !== ''
            && (
                ($held !== null && strspn($held, ' ') >= strlen($marker))
                || !$this->opensMarkdownBlock($lazy)
            )
        ) {
            $lines[$index + 1] = $prefix . ($held !== null && strspn($held, ' ') >= strlen($marker) ? substr($held, strlen($marker)) : ltrim($lazy, ' '));

            return;
        }
        $kept[] = rtrim($prefix) . ' ' . self::EMPTY_DEFINITION_ITEM_SENTINEL;
        if (
            $held !== null
            && trim($held) !== ''
            && preg_match('/^ {0,3}(?:[-*+]|[0-9]{1,9}[.)])(?:[ \t]|$)/', $held) !== 1
        ) {
            $kept[] = rtrim($quotePrefix);
        }
    }

    /**
     * Whether the definition ending at `$index` is all that stands between a
     * list above it and an item below it in the same container.
     *
     * @param array<string> $lines
     * @param int $index
     * @param array<string> $kept
     * @param string $quotePrefix
     * @param array<int, int>|null $listOwners Content columns of the items holding the definition.
     * @param bool $listAbove Whether a list precedes the definition.
     */
    protected function partsTwoLists(array $lines, int $index, array $kept, string $quotePrefix, ?array $listOwners = null, bool $listAbove = false): bool
    {
        if ($listOwners !== null && $listOwners !== []) {
            return false;
        }
        $depth = substr_count($quotePrefix, '>');
        $item = '/^ {0,3}(?:[-*+]|[0-9]{1,9}[.)])(?:[ \t]|$)/';
        $below = null;
        for ($at = $index + 1, $count = count($lines); $at < $count; $at++) {
            $prefix = $this->quotePrefixOf($lines[$at]);
            if (substr_count($prefix, '>') !== $depth) {
                return false;
            }
            $text = (string)substr($lines[$at], strlen($prefix));
            if (trim($text) !== '') {
                $below = $text;

                break;
            }
        }
        if ($below === null || preg_match(self::THEMATIC_BREAK, preg_replace('/^ {0,3}/', '', $below) ?? $below) === 1 || preg_match($item, $below) !== 1) {
            return false;
        }
        if ($listAbove) {
            return true;
        }
        if ($listOwners !== null) {
            return false;
        }
        for ($at = count($kept) - 1; $at >= 0; $at--) {
            $prefix = $this->quotePrefixOf($kept[$at]);
            if (substr_count($prefix, '>') !== $depth) {
                return false;
            }
            $text = (string)substr($kept[$at], strlen($prefix));
            if (trim($text) !== '') {
                return preg_match(self::THEMATIC_BREAK, preg_replace('/^ {0,3}/', '', $text) ?? $text) !== 1
                    && (preg_match($item, $text) === 1 || preg_match('/^[ \t]+\S/', $text) === 1);
            }
        }

        return false;
    }

    /**
     * Whether a Markdown line opens a block, so it cannot continue a paragraph.
     */
    protected function opensMarkdownBlock(string $line): bool
    {
        return preg_match('/^ {0,3}(?:>|#{1,6}(?:[ \t]|$)|`{3,}|~{3,}|(?:[-*+]|[0-9]{1,9}[.)])(?:[ \t]|$)|([-*_])(?:[ \t]*\1){2,}[ \t]*$)/', $line) === 1
            || ($this->htmlBlockInterrupts)(ltrim($line, ' '));
    }

    /**
     * Read a reference definition at `$start` inside a block quote whose title
     * wraps. Continuation lines carry the quote marker or are lazy. `target` is
     * null for an empty destination.
     *
     * @param array<string> $lines
     * @param int $start
     * @param int $depth
     *
     * @return array{label: string, title: string, target: string|null, lines: int}|null
     */
    protected function readQuotedWrappedDefinition(array $lines, int $start, int $depth): ?array
    {
        $chunk = [];
        $count = count($lines);
        $destination = false;
        $closer = null;
        // Collect only while a title is open, so a run of definitions stays linear.
        for ($at = $start; $at < $count; $at++) {
            $prefix = $this->quotePrefixOf($lines[$at]);
            $lineDepth = substr_count($prefix, '>');
            $body = substr($lines[$at], strlen($prefix));
            if ($at > $start && ($lineDepth > $depth || trim($body) === '' || $this->opensMarkdownBlock($body))) {
                break;
            }
            $chunk[] = $body;
            $rest = preg_replace('/\\\\./', '', $at === $start ? (string)preg_replace('/^ {0,3}\[(?:[^[\]\\\\\n]|\\\\.)+\]:/', '', $body) : $body) ?? '';
            if (!$destination) {
                $rest = ltrim($rest);
                if ($rest === '') {
                    continue;
                }
                $destination = true;
                $end = $rest[0] === '<'
                    ? (preg_match('/^<(?:[^<>\n\\\\]|\\\\.)*>/', $rest, $angle) === 1 ? strlen($angle[0]) - 1 : false)
                    : strcspn($rest, " \t");
                if ($end === false) {
                    return null;
                }
                $rest = substr($rest, $rest[0] === '<' ? $end + 1 : $end);
            }
            if ($closer === null) {
                $rest = ltrim($rest);
                if ($rest === '') {
                    continue;
                }
                $closer = ['"' => '"', "'" => "'", '(' => '()'][$rest[0]] ?? null;
                if ($closer === null) {
                    return null;
                }
                $rest = substr($rest, 1);
            }
            if (strpbrk($rest, $closer) !== false) {
                break;
            }
        }
        if ($closer === null) {
            return null;
        }
        $parsed = ReferenceDefinition::read(implode("\n", $chunk));
        if (
            $parsed === null
            || str_contains($parsed['label'], "\n")
            || preg_match('/^(?:<(?:[^<>\n\\\\]|\\\\.)*>|[^<\s]\S*)\s+("(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|\((?:\\\\.|[^()\\\\])*\))\s*\z/', $parsed['target'], $titled) !== 1
            || !str_contains($titled[1], "\n")
        ) {
            return null;
        }

        return [
            'label' => $parsed['label'],
            'title' => $this->inlineStage->decodeLinkTitle(substr($titled[1], 1, -1)),
            'target' => str_starts_with($parsed['target'], '<>') ? null : $parsed['target'],
            'lines' => $parsed['lines'],
        ];
    }

    /**
     * Read a reference definition at `$start` inside a block quote that runs
     * past its first line: a wrapped title, or a label, destination or title
     * on the next lines.
     *
     * @param array<string> $lines
     * @param int $start
     * @param int $depth
     * @param string $content
     *
     * @return array{label: string, title: string, target: string|null, lines: int}|null
     */
    protected function readQuotedDefinition(array $lines, int $start, int $depth, string $content): ?array
    {
        $wrapped = preg_match('/^ {0,3}\[(?!\^)(?:[^[\]\\\\\n]|\\\\.)+\]:/', $content) === 1
            ? $this->readQuotedWrappedDefinition($lines, $start, $depth) : null;

        return $wrapped ?? $this->readQuotedSpreadDefinition($lines, $start, $depth);
    }

    /**
     * Read a reference definition at `$start` inside a block quote whose label,
     * destination or title runs onto the next lines, quoted or lazy. Null when
     * it fits on its own line, which the single-line reading takes.
     *
     * @param array<string> $lines
     * @param int $start
     * @param int $depth
     *
     * @return array{label: string, title: string, target: string|null, lines: int}|null
     */
    protected function readQuotedSpreadDefinition(array $lines, int $start, int $depth): ?array
    {
        $chunk = [];
        $last = min(count($lines), $start + self::SPREAD_DEFINITION_LINES);
        for ($at = $start; $at < $last; $at++) {
            $prefix = $this->quotePrefixOf($lines[$at]);
            $lineDepth = substr_count($prefix, '>');
            $body = substr($lines[$at], strlen($prefix));
            if ($at > $start && ($lineDepth > $depth || trim($body) === '' || $this->opensMarkdownBlock($body))) {
                break;
            }
            $chunk[] = $lineDepth === $depth ? $body : ltrim($body, ' ');
        }
        $parsed = ReferenceDefinition::read(implode("\n", $chunk));
        if ($parsed === null || $parsed['lines'] < 2) {
            return null;
        }
        if (str_starts_with($parsed['target'], '<>')) {
            if (preg_match('/^<>\s+("(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|\((?:\\\\.|[^()\\\\])*\))\s*\z/', $parsed['target'], $titled) !== 1) {
                return null;
            }

            return [
                'label' => $parsed['label'],
                'title' => $this->inlineStage->decodeLinkTitle(substr($titled[1], 1, -1)),
                'target' => null,
                'lines' => $parsed['lines'],
            ];
        }

        return ['label' => $parsed['label'], 'title' => '', 'target' => $parsed['target'], 'lines' => $parsed['lines']];
    }

    /**
     * Drop the definition ending at `$index`. A blank after it goes too where
     * nothing of its container comes before it or a blank does. A quote left
     * with nothing else keeps an empty line, so it still renders.
     *
     * A lazy line after it continues the paragraph it sat in, so it moves into
     * the quote, which the return value reports; and a definition that parted
     * two quotes leaves a blank line, so they stay two.
     *
     * @param array<string> $lines
     * @param int $index
     * @param string $quotePrefix
     * @param array<string> $kept
     * @param int $listIndent Content column of the item holding the quote.
     */
    protected function dropDefinitionLine(array $lines, int &$index, string $quotePrefix, array &$kept, int $listIndent = 0): bool
    {
        $marker = rtrim($quotePrefix);
        $after = $lines[$index + 1] ?? null;
        // A lazy line keeps no more of the quote markers than it has.
        $afterPrefix = $after === null ? '' : $this->quotePrefixOf($after);
        if ($after !== null && $listIndent > 0 && strspn($after, ' ') >= $listIndent) {
            $afterPrefix = substr($after, 0, $listIndent) . $this->quotePrefixOf(substr($after, $listIndent));
        }
        $afterBody = $after === null ? '' : substr($after, strlen($afterPrefix));
        if (
            $marker !== ''
            && $after !== null
            && trim($afterBody) !== ''
            && substr_count($afterPrefix, '>') < substr_count($marker, '>')
            && !$this->opensMarkdownBlock($afterBody)
            && preg_match('/^ {0,3}\[(?:[^[\]\\\\]|\\\\.)+\]:/', $afterBody) !== 1
        ) {
            $kept[] = $quotePrefix . ltrim($afterBody, ' ');
            $index++;

            return true;
        }
        $before = end($kept);
        $inner = is_string($before) ? substr($before, strlen($this->quotePrefixOf($before))) : '';
        if (
            is_string($before)
            && (
                substr_count($this->quotePrefixOf($before), '>') > substr_count($marker, '>')
                || (trim($inner) !== '' && preg_match('/^(?:[ \t]+\S| {0,3}(?:[-*+]|[0-9]{1,9}[.)])[ \t])/', $inner) === 1 && strspn(substr($lines[$index], strlen($quotePrefix)), ' ') === 0)
            )
        ) {
            $kept[] = $marker;

            return false;
        }
        $holds = fn (string|false|null $line): bool => is_string($line)
            && $marker !== ''
            && str_starts_with(ltrim($line, ' '), $marker)
            && trim(substr(ltrim($line, ' '), strlen($marker))) !== '';
        $next = $lines[$index + 1] ?? null;
        $last = end($kept);
        $opened = is_string($last) && $marker !== '' && rtrim(ltrim($last, ' ')) === $marker;
        if ($marker !== '' && !$holds($last) && !$holds($next) && !$opened) {
            $kept[] = $quotePrefix;
        }
        if ($next === null || !str_starts_with($next, $marker) || trim(substr($next, strlen($marker))) !== '') {
            return false;
        }
        if ($last === false || !str_starts_with($last, $marker) || trim(substr($last, strlen($marker))) === '') {
            $index++;
        }

        return false;
    }

    /**
     * The quote markers a Markdown line opens with.
     */
    public function quotePrefixOf(string $line): string
    {
        return preg_match('/^((?: {0,3}>[ \t]?)*)/', $line, $match) === 1 ? $match[1] : '';
    }
}
