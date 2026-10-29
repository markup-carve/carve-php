<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Transform;

use Closure;
use InvalidArgumentException;
use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\Ast\TextRunCoalescer;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\ParseWarning;
use MarkupCarve\Carve\Exception\UnresolvedIncludeException;
use MarkupCarve\Carve\Extension\Frontmatter;
use MarkupCarve\Carve\Node\Block\AbbreviationDefinition;
use MarkupCarve\Carve\Node\Block\BlockNode;
use MarkupCarve\Carve\Node\Block\Caption;
use MarkupCarve\Carve\Node\Block\CitationDefinition;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\DefinitionDescription;
use MarkupCarve\Carve\Node\Block\DefinitionTerm;
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\FigureGroup;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\LineBlock;
use MarkupCarve\Carve\Node\Block\LinkReferenceDefinition;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\ContentNodeInterface;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\CaptionNumber;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\HeadingRef;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\InlineNode;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\DelimitedTable;
use MarkupCarve\Carve\Parser\SourceMap;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;
use MarkupCarve\Carve\Renderer\Utility\DocumentRenderOrder;
use Throwable;
use WeakMap;

/**
 * Expands processor-level {{ ... }} include directives in parsed text nodes.
 */
class IncludeExpander implements TransformerInterface
{
    /**
     * @var \WeakMap<\MarkupCarve\Carve\Ast\SourceSpan, list<array{file: string, line: int, column: int}>>|null
     */
    private static ?WeakMap $positionIncludeSites = null;

    /**
     * @return list<array{file: string, line: int, column: int}>|null
     */
    public static function includeSitesForPosition(SourceSpan $position): ?array
    {
        return self::$positionIncludeSites[$position] ?? null;
    }

    /**
     * @var int
     */
    private const DEFAULT_DEPTH_LIMIT = 16;

    /**
     * @var int
     */
    private const DEFAULT_MIN_BYTE_BUDGET = 1048576;

    /**
     * Resolver calls allowed for one document, counted across the whole
     * recursive expansion. The byte budget bounds how much expanded source a
     * document may produce; it does NOT bound how much work the pass does to
     * get there, because a refused directive is still resolved first. A
     * document is free to carry one directive per dozen bytes, so without this
     * a megabyte of directives is tens of thousands of resolver calls - and a
     * filesystem resolver reads its whole target on every one of them.
     *
     * @var int
     */
    private const DEFAULT_RESOLVER_CALL_LIMIT = 1000;

    /**
     * Include warnings retained for one document. Warnings are per-directive,
     * so a document of refused directives otherwise allocates one per
     * directive. One
     * warning per distinct rule always survives the cap, so a capped report
     * still shows every failure class, and getSuppressedWarnings() reports the
     * remainder rather than letting it read as a clean run.
     *
     * @var int
     */
    private const DEFAULT_WARNING_LIMIT = 100;

    /**
     * Stable, host-independent rule ids stamped on every include warning. They
     * are the machine-readable cross-engine contract (carve-js / carve-php /
     * carve-rs emit the SAME id for the same condition), asserted by the
     * include-conformance suite; the human `message` prose is not.
     *
     * @var string
     */
    public const RULE_UNRESOLVED = 'include-unresolved';

    /**
     * @var string
     */
    public const RULE_NON_TEXT = 'include-non-text';

    /**
     * @var string
     */
    public const RULE_CYCLE = 'include-cycle';

    /**
     * @var string
     */
    public const RULE_DEPTH = 'include-depth';

    /**
     * @var string
     */
    public const RULE_BUDGET = 'include-budget';

    /**
     * @var string
     */
    public const RULE_SELECTION_CONFLICT = 'include-selection-conflict';

    /**
     * @var string
     */
    public const RULE_REGION_INVALID = 'include-region-invalid';

    /**
     * @var string
     */
    public const RULE_BLOCK_IN_INLINE = 'include-block-in-inline';

    /**
     * @var string
     */
    public const RULE_SECTION = 'include-section';

    /**
     * @var string
     */
    public const RULE_HEADING_CLAMP = 'include-heading-clamp';

    /**
     * @var string
     */
    public const RULE_ID_RENAME = 'include-id-rename';

    /**
     * @var string
     */
    public const RULE_FOOTNOTE_RENAME = 'include-footnote-rename';

    /**
     * @var string
     */
    public const RULE_UNKNOWN_OPTION = 'include-unknown-option';

    /**
     * @var string
     */
    public const RULE_DUPLICATE_OPTION = 'include-duplicate-option';

    /**
     * @var string
     */
    public const RULE_LINES_OUT_OF_RANGE = 'include-lines-out-of-range';

    /**
     * @var string
     */
    public const RULE_LINES_INVALID = 'include-lines-invalid';

    /**
     * @var string
     */
    public const RULE_CALL_LIMIT = 'include-call-limit';

    /**
     * @var list<\MarkupCarve\Carve\Exception\ParseWarning>
     */
    protected array $warnings = [];

    protected int $bytesUsed = 0;

    protected int $admittedBytes = 0;

    protected int $resolverCalls = 0;

    protected int $suppressedWarnings = 0;

    /**
     * Whether the document being expanded carries source positions.
     *
     * Inferred from the parent rather than configured, because a half
     * positioned tree is worse than either: a host that asked for positions
     * and got them for its own blocks but not for included ones cannot tell
     * "no position" from "position it could not compute".
     */
    protected bool $trackPositions = false;

    /**
     * Rules already represented in $warnings, so the cap never hides a class.
     *
     * @var array<string, true>
     */
    protected array $seenRules = [];

    /**
     * Rule id of the first guard to refuse on a whole-document resource - the
     * byte budget or the resolver-call limit - or null while both have room.
     * Both are totals that only ever grow, so once either is spent no later
     * directive can succeed. Latching stops the pass from resolving the rest
     * of the document only to refuse each directive individually: the refusal
     * is already decided, and resolving to reach it is what turns a megabyte
     * of directives into tens of thousands of file reads.
     */
    protected ?string $resourcesSpent = null;

    /**
     * @var array<int, string>
     */
    protected array $scopeByObjectId = [];

    /**
     * Identity of the file whose content is being expanded right now, used to
     * attribute warnings (spec I4). A directive that fails to resolve is
     * attributed to the document that CONTAINS it, so this only advances once
     * the child is actually being walked.
     */
    protected ?string $warningFile = null;

    /**
     * @var list<array{file: string, line: int, column: int}>
     */
    protected array $includeChain = [];

    /**
     * @var array{line: int, column: int}
     */
    protected array $warningSite = ['line' => 0, 'column' => 0];

    /**
     * @var list<array{file: string, includedBy: list<array{file: string, line: int, column: int}>, source: string, lineBase: int, section: array{start: int, end: int}|null, block: \MarkupCarve\Carve\Node\Block\CodeBlock|\MarkupCarve\Carve\Node\Block\Table|null, payload: string|null, tableLinesHint: bool}>
     */
    protected array $included = [];

    protected bool $collectIncluded = false;

    public function collectIncluded(bool $enabled = true): void
    {
        $this->collectIncluded = $enabled;
    }

    /**
     * @return list<array{file: string, includedBy: list<array{file: string, line: int, column: int}>, source: string, lineBase: int, section: array{start: int, end: int}|null, block: \MarkupCarve\Carve\Node\Block\CodeBlock|\MarkupCarve\Carve\Node\Block\Table|null, payload: string|null, tableLinesHint: bool}>
     */
    public function getIncluded(): array
    {
        return $this->included;
    }

    /**
     * Scope id to file identity, so the collision pass - which runs once over
     * the assembled document, after every path context has been popped - can
     * still name the file each renamed id or label came from.
     *
     * @var array<string, string>
     */
    protected array $fileByScope = [];

    /**
     * @var array<string, \MarkupCarve\Carve\Transform\IncludeDependency>
     */
    protected array $dependencies = [];

    protected int $scopeSeq = 0;

    /**
     * Scope id to its place in inclusion order: parent before child, earlier
     * include before later. The top-level document ('') ranks first.
     *
     * @var array<string, int>
     */
    protected array $scopeRank = [];

    /**
     * Lazily built in parseChild(); see there for why it is not the host's.
     *
     * @var \MarkupCarve\Carve\CarveConverter|null
     */
    protected ?CarveConverter $childConverter = null;

    /**
     * @param \MarkupCarve\Carve\Transform\IncludeResolverInterface|null $resolver
     * @param string|null $currentPath
     * @param int $depthLimit
     * @param int|null $byteBudget
     * @param string|null $source Parsed source of the document, when the host
     *   still has it. Supplying it lets the pass skip its AST walk entirely
     *   for documents that cannot contain a directive.
     * @param int $resolverCallLimit Resolver calls allowed for one document.
     * @param int $warningLimit
     *   Bounds the pass's own work - reads, lookups - which the byte budget
     *   does not, since a directive is resolved before it can be refused.
     * @param array<\MarkupCarve\Carve\Extension\ExtensionInterface> $extensions
     *   Extensions a child is parsed with. Pass the parent converter's
     *   `getExtensions()`: the same text has to mean the same thing whichever
     *   file it sits in.
     */
    public function __construct(
        protected ?IncludeResolverInterface $resolver = null,
        protected ?string $currentPath = null,
        protected int $depthLimit = self::DEFAULT_DEPTH_LIMIT,
        protected ?int $byteBudget = null,
        protected ?string $source = null,
        protected int $resolverCallLimit = self::DEFAULT_RESOLVER_CALL_LIMIT,
        protected int $warningLimit = self::DEFAULT_WARNING_LIMIT,
        protected array $extensions = [],
    ) {
    }

    /**
     * Whether the include pass runs for a given output format (spec I15).
     *
     * Only Carve source opts out, and the reason is not performance: that
     * target writes the document back as Carve, and expanding first returns a
     * DIFFERENT document, with every child inlined and the directives gone. The
     * writer already preserves a directive verbatim (I12); expanding before it
     * runs takes that away by another route.
     *
     * The JSON / AST dump is NOT Carve source - it publishes a tree - so it
     * expands, matching carve-js and carve-rs.
     *
     * Lives here rather than in `bin/carve` so the rule has one home, read by
     * the CLI and by the include-conformance suite alike. carve-js kept it
     * inline in its CLI and inlined every child on `render --carve` as a result.
     *
     * @param string $format The CLI output format (`html`, `carve`, `json`, ...).
     *
     * @return bool
     */
    public static function expandsForFormat(string $format): bool
    {
        return $format !== 'carve';
    }

    public function transform(Document $document): Document
    {
        $this->warnings = [];
        $this->bytesUsed = 0;
        $this->admittedBytes = 0;
        $this->resolverCalls = 0;
        $this->resourcesSpent = null;
        $this->suppressedWarnings = 0;
        $this->seenRules = [];
        $this->trackPositions = $this->carriesPositions($document);
        $this->scopeByObjectId = [];
        $this->dependencies = [];
        $this->scopeSeq = 0;
        $this->scopeRank = [];
        $this->fileByScope = [];
        $this->warningFile = $this->currentPath;
        $this->includeChain = [];
        $this->included = [];
        $this->warningSite = ['line' => 0, 'column' => 0];

        if ($this->resolver === null) {
            return $document;
        }

        // Recognition needs a parse, but a document whose source contains no
        // '{{' at all cannot contain a directive in any position, so the walk
        // is skipped outright. This keeps directive-free documents at parse
        // cost.
        if ($this->source !== null && !str_contains($this->source, '{{')) {
            return $document;
        }

        $transformed = clone $document;

        $budget = $this->byteBudget ?? max(self::DEFAULT_MIN_BYTE_BUDGET, 8 * max(1, $document->getSourceLength()));
        $rootStack = $this->currentPath !== null ? [$this->currentPath] : [];
        $this->expandChildren($transformed, $this->currentPath, $rootStack, 0, $budget);
        $this->resolveCollisions($transformed);
        $transformed->addAdmittedIncludeLength($this->admittedBytes);

        return $transformed;
    }

    /**
     * @return list<\MarkupCarve\Carve\Exception\ParseWarning>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Every include target touched during the whole recursive expansion, in the
     * order each target's directive is first encountered reading the fully
     * expanded document top to bottom.
     *
     * Deliberately NOT sorted. The set is a cross-implementation contract, so
     * an editor diffing dependency lists across engines has to see the same
     * sequence; document order is the sequence a host can reason about, and
     * sorting was an artifact rather than a decision.
     *
     * @return list<\MarkupCarve\Carve\Transform\IncludeDependency>
     */
    public function getDependencies(): array
    {
        return array_values($this->dependencies);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param string|null $currentPath
     * @param list<string> $stack
     * @param int $budget
     * @param int $depth
     */
    protected function expandChildren(Node $node, ?string $currentPath, array $stack, int $depth, int $budget): void
    {
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Paragraph) {
                $this->expandParagraph($node, $child, $currentPath, $stack, $depth, $budget);

                continue;
            }

            if ($child instanceof Heading) {
                // A heading holds INLINE content, so a directive in it expands
                // as inline (I2), never as a block. Its children are replaced in
                // place here, before render, so the heading text - and the id
                // slugged from that text at render time - both come from the
                // resolved content, matching carve-js / carve-rs. Without this a
                // heading's inline run never reached the scan and the reader saw
                // the literal `{{ ... }}`.
                $this->expandInlineRuns($child, $currentPath, $stack, $depth, $budget);

                continue;
            }

            // ANY node holding inline content directly, not a list of block
            // kinds: a directive is recognized wherever inline content is (I2),
            // and a table cell reaches that content without a paragraph in
            // between. Enumerating block classes missed cells, which rendered
            // the literal `{{ ... }}` where carve-js and carve-rs expanded it.
            if ($this->holdsInlineContent($child)) {
                $this->expandInlineRuns($child, $currentPath, $stack, $depth, $budget);

                continue;
            }

            $this->expandChildren($child, $currentPath, $stack, $depth, $budget);
        }
    }

    /**
     * Whether this node's own children are inline nodes, i.e. whether it holds
     * a run a directive could sit in.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     *
     * @return bool
     */
    protected function holdsInlineContent(Node $node): bool
    {
        foreach ($node->getChildren() as $child) {
            if ($child instanceof InlineNode) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param \MarkupCarve\Carve\Node\Block\Paragraph $paragraph
     * @param string|null $currentPath
     * @param list<string> $stack
     * @param int $budget
     * @param int $depth
     */
    protected function expandParagraph(
        Node $parent,
        Paragraph $paragraph,
        ?string $currentPath,
        array $stack,
        int $depth,
        int $budget,
    ): void {
        $children = array_values($paragraph->getChildren());
        if ($children !== [] && $this->allTextLike($children)) {
            $content = $this->textLikeContent($children);
            $pos = $paragraph->getPos();
            $this->warningSite = ['line' => $pos === null ? 0 : $pos->startLine, 'column' => $pos === null ? 0 : $pos->startColumn];
            $directive = $this->parseDirective($content);
            if ($directive !== null) {
                $slot = count($this->included);
                $replacement = $this->resolveDirective($directive, true, $currentPath, $stack, $depth, $budget, $parent, $paragraph);
                if ($replacement !== null) {
                    $hostOffset = $paragraph->getRenderHint('includeHostOffset');
                    if ($hostOffset === null && $paragraph->getPos() !== null) {
                        $hostOffset = (string)$paragraph->getPos()->startOffset;
                    }
                    if ($hostOffset !== null) {
                        foreach ($replacement as $node) {
                            $node->setRenderHint('includeHostOffset', $hostOffset);
                        }
                    }
                    if ($directive['code'] !== null && isset($replacement[0]) && $replacement[0] instanceof CodeBlock) {
                        $replacement[0]->setAttributesWithOrder($paragraph->getAttributeEntries(), $paragraph->getAttributeOrder());
                        // `{table}` converts a csv/tsv code include as it does an authored fence (I1c).
                        $table = $replacement[0]->getRenderHint('includePartialDelimitedRecord') === '1'
                            ? null : DelimitedTable::fromCode($replacement[0]);
                        if ($table !== null) {
                            BlockParser::applyTableColumns($table);
                            if ($hostOffset !== null) {
                                $table->setRenderHint('includeHostOffset', $hostOffset);
                            }
                            $replacement[0] = $table;
                        }
                        if ($this->collectIncluded && isset($this->included[$slot])) {
                            $this->included[$slot]['block'] = $replacement[0];
                        }
                    }
                    if ($directive['code'] !== null && isset($replacement[0]) && $replacement[0] instanceof Table) {
                        $siblings = array_values($parent->getChildren());
                        $next = $siblings[array_search($paragraph, $siblings, true) + 1] ?? null;
                        if ($next instanceof Paragraph) {
                            $captionText = $this->textLikeContent(array_values($next->getChildren()));
                            if (str_starts_with($captionText, '^ ')) {
                                $caption = new Caption();
                                $caption->appendChild(new Text(substr($captionText, 2)));
                                $caption->setPos($next->getPos());
                                $replacement[0]->setCaption($caption);
                                $parent->removeChild($next);
                            }
                        }
                        if ($this->collectIncluded && isset($this->included[$slot])) {
                            $this->included[$slot]['block'] = $replacement[0];
                        }
                    }
                    $parent->replaceChildWithMany($paragraph, $replacement);
                }

                return;
            }

            // A whole-paragraph directive that failed to parse was already
            // reported here; skip the inline scan so it is not warned twice.
            if (preg_match(IncludeDirectiveSyntax::SHAPE, trim($content)) === 1) {
                return;
            }
        }

        $this->expandInlineRuns($paragraph, $currentPath, $stack, $depth, $budget);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param string|null $currentPath
     * @param list<string> $stack
     * @param int $budget
     * @param int $depth
     */
    protected function expandInlineRuns(Node $node, ?string $currentPath, array $stack, int $depth, int $budget): void
    {
        $children = array_values($node->getChildren());
        $count = count($children);
        $i = 0;
        while ($i < $count) {
            $child = $children[$i];
            if (!$this->isTextLike($child)) {
                $this->expandInlineRuns($child, $currentPath, $stack, $depth, $budget);
                $i++;

                continue;
            }

            $run = [];
            $j = $i;
            while ($j < $count && $this->isTextLike($children[$j])) {
                $run[] = $children[$j];
                $j++;
            }

            $replaced = $this->expandRun($node, $run, $currentPath, $stack, $depth, $budget);
            if ($replaced) {
                $oldCount = $count;
                $children = array_values($node->getChildren());
                $count = count($children);
                // The inserted nodes were expanded in the child's scope before
                // splicing. Resume after them instead of resolving them again
                // in the parent (which can starve later inline includes).
                $i += $count - $oldCount + ($j - $i);

                continue;
            }

            $i = $j;
        }
    }

    /**
     * Scan a contiguous run of text-like inline nodes for directives. The core
     * splits '{{ x #s @shift:1 }}' into text plus tag and mention nodes, so
     * recognition reassembles the run before matching, then replaces only the
     * matched spans. Failed directives keep their original nodes and render
     * exactly as the core does with no resolver.
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param list<\MarkupCarve\Carve\Node\Node> $run
     * @param string|null $currentPath
     * @param list<string> $stack
     * @param int $depth
     * @param int $budget
     */
    protected function expandRun(
        Node $parent,
        array $run,
        ?string $currentPath,
        array $stack,
        int $depth,
        int $budget,
    ): bool {
        $full = $this->textLikeContent($run);
        if (!str_contains($full, '{{')) {
            return false;
        }
        $index = new IncludeRun($run);

        if (preg_match_all(IncludeDirectiveSyntax::SCAN, $full, $matches, PREG_OFFSET_CAPTURE) === false) {
            return false;
        }

        $spans = [];
        $pos = $this->hostSpan($run);
        // Match offsets only grow, so the site advances from the last one
        // instead of rescanning the run's prefix per candidate.
        $scanned = 0;
        $lineOffset = 0;
        $lineChars = 0;
        foreach ($matches[0] as $match) {
            [$literal, $offset] = $match;
            if ($index->matchIsEscaped($offset, strlen($literal))) {
                continue;
            }
            $segment = substr($full, $scanned, $offset - $scanned);
            $scanned = $offset;
            $newlines = substr_count($segment, "\n");
            if ($newlines > 0) {
                $lineOffset += $newlines;
                $lineChars = mb_strlen(substr($segment, (int)strrpos($segment, "\n") + 1), 'UTF-8');
            } else {
                $lineChars += mb_strlen($segment, 'UTF-8');
            }
            $this->warningSite = [
                'line' => $pos === null ? 0 : $pos->startLine + $lineOffset,
                'column' => $pos === null ? 0 : ($lineOffset === 0 ? $pos->startColumn : 1) + $lineChars,
            ];
            $directive = $this->parseDirective($literal);
            if ($directive === null) {
                continue;
            }

            $replacement = $this->resolveDirective($directive, false, $currentPath, $stack, $depth, $budget);
            if ($replacement === null) {
                continue;
            }

            $spans[] = ['start' => $offset, 'end' => $offset + strlen($literal), 'nodes' => $replacement];
        }

        if ($spans === []) {
            return false;
        }

        $nodes = [];
        $cursor = 0;
        foreach ($spans as $span) {
            array_push($nodes, ...$this->sliceRun($run, $cursor, $span['start'], $index));
            array_push($nodes, ...$span['nodes']);
            $cursor = $span['end'];
        }
        array_push($nodes, ...$this->sliceRun($run, $cursor, strlen($full), $index));
        $nodes = $this->mergeExpandedRun($nodes, $this->hostSpan($run));

        for ($remove = count($run) - 1; $remove >= 1; $remove--) {
            $parent->removeChild($run[$remove]);
        }
        $parent->replaceChildWithMany($run[0], $nodes);

        return true;
    }

    /**
     * Return the run nodes covering [$from, $to) of the run's reassembled text.
     * A directive match starts with '{{' and ends with '}}', which the core
     * always parses as text, so a boundary can only fall inside a Text node;
     * mention and tag nodes are either fully kept or fully consumed.
     *
     * @param list<\MarkupCarve\Carve\Node\Node> $run
     * @param int $from
     * @param int $to
     * @param \MarkupCarve\Carve\Transform\IncludeRun|null $index The run, already indexed.
     *
     * @return list<\MarkupCarve\Carve\Node\Node>
     */
    protected function sliceRun(array $run, int $from, int $to, ?IncludeRun $index = null): array
    {
        $index ??= new IncludeRun($run);
        $out = [];
        $count = count($run);
        for ($i = $index->nodeAt($from); $i < $count; $i++) {
            $node = $run[$i];
            $text = $index->piece($i);
            $start = $index->startOf($i);
            $end = $start + strlen($text);
            if ($start >= $to) {
                break;
            }
            if ($end <= $from) {
                continue;
            }

            if (!$node instanceof Text) {
                $out[] = $node;

                continue;
            }

            $sliceFrom = max($from, $start) - $start;
            $value = substr($text, $sliceFrom, min($to, $end) - max($from, $start));
            if ($value === $text) {
                $out[] = $node;
            } elseif ($value !== '') {
                $piece = new Text($value);
                $piece->setPos($this->narrowedSpan($node->getPos(), $text, $sliceFrom, strlen($value)));
                $out[] = $piece;
            }
        }

        return $out;
    }

    /**
     * @param array{literal: string, path: string, section: string|null, lines: array{start: int, end: int}|null, rows: array{start: int, end: int}|null, cols: list<string>|array{start: int, end: int}|null, invalidRows: bool, invalidCols: bool, repeatedTableOption: bool, region: string|null, code: array{lang: string|null}|null, shift: int|'auto', invalidLines: string|null} $directive
     * @param string|null $currentPath
     * @param bool $block
     * @param list<string> $stack
     * @param int $budget
     * @param int $depth
     * @param \MarkupCarve\Carve\Node\Node|null $contextParent
     * @param \MarkupCarve\Carve\Node\Node|null $contextNode
     *
     * @return list<\MarkupCarve\Carve\Node\Node>|null
     */
    protected function resolveDirective(
        array $directive,
        bool $block,
        ?string $currentPath,
        array $stack,
        int $depth,
        int $budget,
        ?Node $contextParent = null,
        ?Node $contextNode = null,
    ): ?array {
        $hasLinesOption = $directive['lines'] !== null;
        if ($directive['rows'] === null && (count(array_filter([$directive['section'], $directive['lines'], $directive['region']], static fn ($value) => $value !== null)) > 1 || ($directive['section'] !== null && $directive['code'] !== null))) {
            $this->warn('Include directive cannot combine selectors', self::RULE_SELECTION_CONFLICT);

            return null;
        }

        if ($depth >= $this->depthLimit) {
            $this->recordDependency($directive['path'], false);
            $this->warn("Include depth limit exceeded for '{$directive['path']}'", self::RULE_DEPTH);

            return null;
        }

        // A document-wide resource is already spent, so this directive cannot
        // expand whatever it resolves to. Refuse it WITHOUT resolving: the
        // target is never read, and the dependency is recorded unresolved
        // because it genuinely was not.
        $spent = $this->resourcesSpent;
        if ($spent !== null) {
            $this->recordDependency($directive['path'], false);
            $this->warn($this->spentMessage($spent, $directive['path']), $spent);

            return null;
        }

        if ($this->resolverCalls >= $this->resolverCallLimit) {
            $this->resourcesSpent = self::RULE_CALL_LIMIT;
            $this->recordDependency($directive['path'], false);
            $this->warn($this->spentMessage(self::RULE_CALL_LIMIT, $directive['path']), self::RULE_CALL_LIMIT);

            return null;
        }

        $this->resolverCalls++;

        try {
            $resolved = $this->resolver?->resolve(
                $directive['path'],
                new IncludeContext($currentPath, $currentPath, $stack, $depth),
            );
        } catch (Throwable $exception) {
            // I11: a resolver that can say where the target would be reports
            // that path, which is the one a host watches; any other refusal
            // keeps the directive's spelling.
            $this->recordDependency(
                $exception instanceof UnresolvedIncludeException
                    ? $exception->getTargetId()
                    : $directive['path'],
                false,
            );
            // The resolver's own message is NOT the warning text. A filesystem
            // resolver routinely embeds absolute paths in it, so propagating it
            // verbatim leaks host directory layout into rendered output. The
            // raw message is still available to hosts on the detail channel,
            // which they can choose not to render.
            $this->warn(
                "Include could not be resolved: {$directive['path']}",
                self::RULE_UNRESOLVED,
                detail: $exception->getMessage(),
            );

            return null;
        }

        if ($resolved === null) {
            $this->recordDependency($directive['path'], false);
            $this->warn("Include could not be resolved: {$directive['path']}", self::RULE_UNRESOLVED);

            return null;
        }

        $source = $resolved instanceof ResolvedInclude ? $resolved->getSource() : $resolved;
        $id = $resolved instanceof ResolvedInclude ? ($resolved->getId() ?? $directive['path']) : $directive['path'];
        // Content that is not decodable text was never successfully READ, so it
        // is recorded unresolved and the target never reaches the resolved
        // state below.
        if (str_contains($source, "\0") || preg_match('//u', $source) !== 1) {
            $this->recordDependency($id, false);
            $this->warn("Include target is binary or non-text: {$directive['path']}", self::RULE_NON_TEXT);

            return null;
        }

        // The source is in hand and decodable: the target WAS read. Every
        // refusal past this point (cycle, budget) is about whether the content
        // may be expanded, not about whether the file could be read, and is
        // surfaced through a Warning instead. Downgrading the flag here would
        // leave a host watching fewer files than it should.
        $this->recordDependency($id, true);
        if ($directive['rows'] !== null && ($directive['section'] !== null || $directive['lines'] !== null || $directive['region'] !== null)) {
            $this->warn('Include directive cannot combine selectors', self::RULE_SELECTION_CONFLICT);

            return null;
        }
        if ($directive['invalidRows'] || $directive['invalidCols'] || $directive['repeatedTableOption']) {
            $rule = $directive['repeatedTableOption'] ? self::RULE_DUPLICATE_OPTION : ($directive['invalidRows'] ? 'include-rows-invalid' : 'include-cols-invalid');
            $this->warn("Include table option is invalid: {$directive['path']}", $rule);

            return null;
        }
        if (
            ($directive['rows'] !== null || $directive['cols'] !== null)
            && (!$block || !$contextNode instanceof Paragraph || $contextNode->getAttribute('table') !== ''
                || !in_array($directive['code']['lang'] ?? null, ['csv', 'tsv'], true))
        ) {
            $this->warn("Include table option requires a marked CSV or TSV block: {$directive['path']}", $directive['rows'] !== null ? 'include-rows-invalid' : 'include-cols-invalid');

            return null;
        }
        if ($directive['invalidLines'] !== null) {
            $this->warn("Include has an invalid line range '{$directive['invalidLines']}'", self::RULE_LINES_INVALID);

            return null;
        }
        // The cycle guard compares canonical ids after resolution, so a resolver
        // that supplies ids catches 'b.crv' vs './b.crv' spellings of one file.
        if (in_array($id, $stack, true)) {
            $this->warn("Include cycle detected for '{$directive['path']}'", self::RULE_CYCLE);

            return null;
        }

        // CHARGED BEFORE THE CHECK, because by this line the target has been
        // READ: PART 9 §19 says a processor cannot do otherwise - the budget
        // "does not bound the WORK a processor does to produce it, because a
        // target is resolved before its size is known". Charging only what was
        // admitted made bytesUsed unable to tell "read nothing" from "read a
        // file and refused it", and reported 0 for a target the resolver had
        // just handed over in full (carve-php#1953).
        //
        // Nothing else moves: the counter still latches resourcesSpent on the
        // same directive, so every later one is refused WITHOUT being resolved
        // exactly as before, which is what §19 requires of a spent budget.
        $bytes = strlen($source);
        $this->bytesUsed += $bytes;
        if ($this->bytesUsed > $budget) {
            $this->resourcesSpent = self::RULE_BUDGET;
            $this->warn($this->spentMessage(self::RULE_BUDGET, $directive['path']), self::RULE_BUDGET);

            return null;
        }

        $this->admittedBytes += $bytes;
        $sliceLineBase = 0;
        $completeSource = $source;
        if ($directive['region'] !== null) {
            $range = self::regionRange($source, $directive['region'], $directive['code'] !== null);
            if ($range === null) {
                $this->warn("Include has an invalid or missing region '{$directive['region']}': {$directive['path']}", self::RULE_REGION_INVALID);

                return null;
            }
            $directive['lines'] = ['start' => $range[0], 'end' => $range[1]];
        } elseif ($directive['lines'] !== null && $directive['lines']['start'] > $this->lineCount($source)) {
            $this->warn("Include line range starts past the end of file: {$directive['path']}", self::RULE_LINES_OUT_OF_RANGE);

            return null;
        }
        if ($directive['lines'] !== null) {
            $directive['lines']['end'] = min($directive['lines']['end'], $this->lineCount($source));
            $sliceLineBase = $directive['lines']['start'] - 1;
            $source = $this->sliceLines($source, $directive['lines']['start'], $directive['lines']['end']);
        }

        if ($directive['code'] !== null) {
            // Only the file's own BOM is dropped, not a U+FEFF that opens a later selected line.
            $payload = $sliceLineBase === 0 ? (preg_replace('/^\x{FEFF}/u', '', $source) ?? $source) : $source;
            $emptySelection = $directive['lines'] !== null && $directive['lines']['start'] > $directive['lines']['end'];
            $payloadLines = $emptySelection || ($directive['lines'] === null && $payload === '')
                ? []
                : (preg_split('/\r\n|\r|\n/', $payload) ?: []);
            if ($directive['lines'] === null && $payloadLines !== [] && end($payloadLines) === '') {
                array_pop($payloadLines);
            }
            $content = $payloadLines === [] ? '' : implode("\n", $payloadLines) . "\n";
            $code = new CodeBlock($content, $directive['code']['lang']);
            if ($this->trackPositions) {
                $startLine = $sliceLineBase + 1;
                $hasBom = $startLine === 1 && str_starts_with($completeSource, "\xEF\xBB\xBF");
                $startOffset = $this->lineStartOffset($completeSource, $startLine) + (int)$hasBom;
                $endLine = $startLine + max(0, count($payloadLines) - 1);
                $sourceLines = preg_split('/\r\n|\r|\n/', $completeSource) ?: [];
                $lastText = $payloadLines === [] ? '' : ($sourceLines[$endLine - 1] ?? '');
                $endColumn = 1 + mb_strlen($lastText, 'UTF-8');
                $endOffset = $payloadLines === []
                    ? $startOffset
                    : $this->lineStartOffset($completeSource, $endLine) + mb_strlen($lastText, 'UTF-8');
                $code->setPos(new SourceSpan($startLine, $endLine, 1 + (int)$hasBom, $payloadLines === [] ? 1 + (int)$hasBom : $endColumn, $startOffset, $endOffset));
            }
            $document = new Document();
            $document->appendChild($code);
            if (
                $block && $contextNode instanceof Paragraph && $contextNode->getAttribute('table') === ''
                && in_array($directive['code']['lang'], ['csv', 'tsv'], true)
                && ($directive['rows'] !== null || $directive['cols'] !== null || $directive['lines'] !== null || $directive['region'] !== null)
            ) {
                $projected = $this->projectIncludedTable($completeSource, $directive, $contextNode, $code);
                if ($projected === null) {
                    return null;
                }
                $document->setChildren([$projected]);
                if ($projected instanceof Table) {
                    $code = $projected;
                }
            }
        } else {
            $document = $this->parseChild($source);
            if ($directive['lines'] !== null) {
                $this->shiftSourcePositions($document, $completeSource, $source, $sliceLineBase);
            }
        }
        if ($directive['section'] !== null) {
            $document = $this->selectSection($document, $directive['section']);
            if ($document === null) {
                // The dependency stays RESOLVED: the flag records only whether
                // the source was READ (I11), and it was. A host must keep
                // watching this file precisely because editing the child to add
                // the section is what makes the include start working - marking
                // it unresolved would drop the watch that invalidates the
                // preview. The missing section is a Warning, not a read failure.
                $this->warn("Include has no section '#{$directive['section']}': {$directive['path']}", self::RULE_SECTION);

                return null;
            }
        }

        $site = ['file' => $currentPath ?? '', 'line' => $this->warningSite['line'], 'column' => $this->warningSite['column']];
        $reach = [...$this->includeChain, $site];
        $slot = count($this->included);
        if ($this->collectIncluded) {
            $section = null;
            if ($directive['section'] !== null) {
                $selected = DocumentRenderOrder::children($document, $id);
                $first = $selected[0]->getPos() ?? null;
                $last = $selected[count($selected) - 1]->getPos() ?? null;
                if ($first !== null && $last !== null) {
                    $section = ['start' => $first->startLine, 'end' => $last->endLine];
                }
            }
            $this->included[] = [
                'file' => $id,
                'includedBy' => $reach,
                'source' => $source,
                'lineBase' => $sliceLineBase,
                'section' => $section,
                'block' => $directive['code'] !== null ? $code : null,
                'payload' => $directive['code'] !== null ? $content : null,
                'tableLinesHint' => $hasLinesOption && $block && $contextNode instanceof Paragraph
                    && $contextNode->getAttribute('table') === ''
                    && in_array($directive['code']['lang'] ?? null, ['csv', 'tsv'], true),
            ];
        }
        $scope = $directive['path'] . '#' . (++$this->scopeSeq);
        $this->markScope($document, $scope);
        $this->scopeRank[$scope] = $this->scopeSeq;
        $this->fileByScope[$scope] = $id;
        // Everything from here to the restore below operates on the child's own
        // content, so a warning it raises (a heading clamp, a nested cycle)
        // names the child rather than the document that included it.
        $outerFile = $this->warningFile;
        $outerChain = $this->includeChain;
        $outerSite = $this->warningSite;
        $this->warningFile = $id;
        $this->includeChain = $reach;
        if ($directive['code'] === null) {
            $this->expandChildren($document, $id, [...$stack, $id], $depth + 1, $budget);
        }
        $this->stampSourceFile($document, $id, $reach);
        if ($directive['code'] === null) {
            $shift = $directive['shift'] === 'auto'
                ? $this->autoShift($document, $block, $contextParent, $contextNode)
                : $directive['shift'];
            $this->shiftHeadings($document, $shift);
        }
        // Back in the parent: the remaining checks are about the DIRECTIVE, not
        // the child's content, so they are the including document's problem.
        $this->warningFile = $outerFile;
        $this->includeChain = $outerChain;
        $this->warningSite = $outerSite;

        $nodes = DocumentRenderOrder::children($document, $id);
        if ($block) {
            return $nodes;
        }

        if ($nodes === []) {
            return [];
        }

        if (count($nodes) === 1 && $nodes[0] instanceof Paragraph) {
            return array_values($nodes[0]->getChildren());
        }

        $this->warn("Inline include resolved to block content for '{$directive['path']}'", self::RULE_BLOCK_IN_INLINE);
        if ($this->collectIncluded) {
            $this->included = array_slice($this->included, 0, $slot);
        }

        return null;
    }

    /**
     * @param string $source
     * @param array{path: string, code: array{lang: string}, lines: array{start: int, end: int}|null, rows: array{start: int, end: int}|null, cols: list<string>|array{start: int, end: int}|null} $directive
     * @param \MarkupCarve\Carve\Node\Block\Paragraph $host
     * @param \MarkupCarve\Carve\Node\Block\CodeBlock $selectedCode
     */
    private function projectIncludedTable(string $source, array $directive, Paragraph $host, CodeBlock $selectedCode): Table|CodeBlock|null
    {
        $selectedCode->setAttributesWithOrder($host->getAttributeEntries(), $host->getAttributeOrder());
        if ($directive['lines'] !== null && DelimitedTable::fromCode($selectedCode) === null) {
            return $selectedCode;
        }
        $payload = preg_replace('/^\x{FEFF}/u', '', $source) ?? $source;
        $normalized = str_replace(["\r\n", "\r"], "\n", $payload);
        if ($normalized !== '' && !str_ends_with($normalized, "\n")) {
            $normalized .= "\n";
        }
        $fullCode = new CodeBlock($normalized, $directive['code']['lang']);
        $fullCode->setAttributesWithOrder($host->getAttributeEntries(), $host->getAttributeOrder());
        $map = null;
        if ($this->trackPositions) {
            $map = new SourceMap();
            $parts = preg_split('/(\r\n|\r|\n)/', $source, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
            $built = 0;
            $original = 0;
            $line = 1;
            $partCount = count($parts);
            for ($i = 0; $i < $partCount; $i += 2) {
                $text = $parts[$i];
                $bom = $line === 1 && str_starts_with($text, "\xEF\xBB\xBF") ? 3 : 0;
                $text = substr($text, $bom);
                $map->add($built, $original + $bom, strlen($text), $line, 1 + (int)($bom > 0));
                $built += strlen($text);
                $original += strlen($parts[$i]);
                $ending = $parts[$i + 1] ?? '';
                if ($ending !== '') {
                    $map->addFallback($built, $original, 1, $line, 1 + mb_strlen($text, 'UTF-8'));
                    $built++;
                    $original += strlen($ending);
                    $line++;
                }
            }
            $map->withSource($source);
        }
        $table = DelimitedTable::fromCode($fullCode, $map);
        if ($table === null) {
            return $directive['rows'] !== null || $directive['cols'] !== null ? $fullCode : $selectedCode;
        }
        $allRows = array_values($table->getChildren());
        $headCount = count(array_filter($allRows, static fn ($row): bool => $row instanceof TableRow && $row->isHeader()));
        $recordStarts = [];
        $recordSpans = [];
        $previous = 0;
        $lineNumber = 1;
        DelimitedTable::rows($normalized, $directive['code']['lang'] === 'csv' ? ',' : "\t", null, static function (int $start, int $end) use (&$recordStarts, &$recordSpans, &$previous, &$lineNumber, $normalized): void {
            $lineNumber += substr_count($normalized, "\n", $previous, $start - $previous);
            $previous = $start;
            $recordStarts[] = $lineNumber;
            $recordSpans[] = [$lineNumber, $lineNumber + substr_count($normalized, "\n", $start, $end - $start)];
        });
        if ($directive['lines'] !== null) {
            $firstLine = $directive['lines']['start'];
            $lastLine = $directive['lines']['end'];
            foreach ($recordSpans as [$recordFirst, $recordLast]) {
                if ($recordFirst <= $lastLine && $recordLast >= $firstLine && ($recordFirst < $firstLine || $recordLast > $lastLine)) {
                    $selectedCode->setRenderHint('includePartialDelimitedRecord', '1');

                    return $selectedCode;
                }
            }
        }
        if ($directive['rows'] !== null && $directive['rows']['start'] > count($allRows)) {
            $this->warn("Include record range starts past the end of file: {$directive['path']}", 'include-rows-out-of-range');

            return null;
        }
        $body = [];
        foreach ($allRows as $index => $row) {
            if ($index < $headCount) {
                continue;
            }
            if ($directive['rows'] !== null && ($index + 1 < $directive['rows']['start'] || $index + 1 > $directive['rows']['end'])) {
                continue;
            }
            if ($directive['lines'] !== null && (($recordStarts[$index] ?? 0) < $directive['lines']['start'] || ($recordStarts[$index] ?? 0) > $directive['lines']['end'])) {
                continue;
            }
            $body[] = $row;
        }
        $kept = [...array_slice($allRows, 0, $headCount), ...$body];
        if ($directive['cols'] !== null) {
            $columns = [];
            if (!isset($directive['cols']['start'], $directive['cols']['end'])) {
                if ($headCount === 0) {
                    $this->warn('Named include columns require a header row', 'include-cols-invalid');

                    return null;
                }
                $headers = array_map(static function ($cell): string {
                    $first = $cell->getChildren()[0] ?? null;

                    return $first instanceof Text ? $first->getContent() : '';
                }, $allRows[0]->getChildren());
                foreach ($directive['cols'] as $name) {
                    $matches = array_keys($headers, $name, true);
                    if (count($matches) !== 1) {
                        $this->warn("Include column is missing or ambiguous: {$name}", 'include-cols-invalid');

                        return null;
                    }
                    if (in_array($matches[0], $columns, true)) {
                        $this->warn("Include column is duplicated: {$name}", 'include-cols-duplicate');

                        return null;
                    }
                    $columns[] = $matches[0];
                }
            } else {
                $columnStart = $directive['cols']['start'];
                $columnEnd = $directive['cols']['end'];
                if (
                    !is_int($columnStart) || !is_int($columnEnd)
                    || $host->getAttribute('header-rows') !== '0' || $columnEnd > count($allRows[0]->getChildren())
                ) {
                    $this->warn('Numeric include columns require header-rows=0 and must fit the table', 'include-cols-invalid');

                    return null;
                }
                $columns = range($columnStart - 1, $columnEnd - 1);
            }
            foreach ($kept as $row) {
                $cells = array_values($row->getChildren());
                $row->setChildren(array_values(array_filter(array_map(static fn ($index) => $cells[$index] ?? null, $columns))));
            }
        }
        $table->setChildren($kept);
        $table->setSourceRecordWidths(array_map(static fn ($row): int => count($row->getChildren()), $kept));
        $table->setRowGroups($table->statedRowGroups());
        BlockParser::applyTableColumns($table);

        return $table;
    }

    /**
     * Parse one included file AS ITS OWN DOCUMENT (spec I4).
     *
     * Through a converter rather than a bare `BlockParser`, because in this
     * engine two pieces of the LANGUAGE are carried by default extensions:
     * frontmatter (a leading `--- … ---` block is metadata, not a thematic
     * break) and mentions / tags. A bare parser has neither, so an included
     * chapter rendered its own frontmatter as a thematic break plus a
     * paragraph, and `@alice` in a child stayed plain text where the same
     * child rendered on its own - or by carve-js and carve-rs, where both are
     * core - produced a mention.
     *
     * A FRESH converter, not the host's, holding a CLONE of each extension the
     * caller passed. An extension resets its own state in `afterParse()`, so a
     * shared instance would drop what it collected from the parent.
     *
     * @param string $source
     *
     * @return \MarkupCarve\Carve\Node\Document
     */
    protected function parseChild(string $source): Document
    {
        if ($this->childConverter === null) {
            $this->childConverter = CarveConverter::create(
                new BlockParser(trackPositions: $this->trackPositions),
            );
            foreach ($this->extensions as $extension) {
                $this->childConverter->addExtension(clone $extension);
            }
        }

        $document = $this->childConverter->parse($source);

        // THE CHILD'S FRONTMATTER IS THE CHILD'S. It is metadata about the file
        // that was pulled in, not about the document being assembled, and the
        // assembled document already has (or has not) its own. Parsing the
        // child as a whole document is what makes it frontmatter rather than a
        // thematic break; dropping the node here is what keeps it from being
        // written back out in the middle of the parent when the assembled
        // document is serialized.
        $blocks = $document->getChildren();
        $kept = array_values(array_filter(
            $blocks,
            static fn ($block): bool => !($block instanceof Frontmatter),
        ));
        if (count($kept) !== count($blocks)) {
            $document->setChildren($kept);
        }

        return $document;
    }

    /**
     * The directive must be one parsed text node. A bare path containing active
     * inline markers is split by core parsing and remains literal by design
     * (corpus pin: bare-path directive with no active inline markers).
     *
     * @return array{literal: string, path: string, section: string|null, lines: array{start: int, end: int}|null, rows: array{start: int, end: int}|null, cols: list<string>|array{start: int, end: int}|null, invalidRows: bool, invalidCols: bool, repeatedTableOption: bool, region: string|null, code: array{lang: string|null}|null, shift: int|'auto', invalidLines: string|null}|null
     */
    protected function parseDirective(string $text): ?array
    {
        $parts = IncludeDirectiveSyntax::parse($text);
        if ($parts === null) {
            return null;
        }

        if ($parts['error'] !== null && !in_array($parts['error'], [IncludeDirectiveSyntax::ERROR_LINES_INVALID, IncludeDirectiveSyntax::ERROR_ROWS_INVALID, IncludeDirectiveSyntax::ERROR_COLS_INVALID, IncludeDirectiveSyntax::ERROR_TABLE_OPTION_REPEATED], true)) {
            // A directive-shaped run whose options are wrong is worth telling
            // the author about; a run that is not directive-shaped at all is
            // just text and stays silent.
            if ($parts['error'] === IncludeDirectiveSyntax::ERROR_UNKNOWN_OPTION) {
                $this->warn("Unknown include option '{$parts['errorPart']}'", self::RULE_UNKNOWN_OPTION);
            } elseif ($parts['error'] === IncludeDirectiveSyntax::ERROR_DUPLICATE_SECTION) {
                // Over-specified selection, the family section 19 already warns
                // about for a `#section` paired with a line range.
                $this->warn(
                    "Include directive cannot name two sections: '{$parts['errorPart']}'",
                    self::RULE_SELECTION_CONFLICT,
                );
            } elseif ($parts['error'] === IncludeDirectiveSyntax::ERROR_DUPLICATE_OPTION) {
                $this->warn("Include option '{$parts['errorPart']}' repeats an option given earlier", self::RULE_DUPLICATE_OPTION);
            }

            return null;
        }

        return [
            'literal' => $text,
            'path' => $parts['path'],
            'section' => $parts['section'],
            'lines' => $parts['lines'],
            'rows' => $parts['rows'],
            'cols' => $parts['cols'],
            'invalidRows' => $parts['invalidRows'],
            'invalidCols' => $parts['invalidCols'],
            'repeatedTableOption' => $parts['error'] === IncludeDirectiveSyntax::ERROR_TABLE_OPTION_REPEATED,
            'region' => $parts['region'],
            'code' => $parts['code'],
            'shift' => $parts['shift'],
            'invalidLines' => $parts['error'] === IncludeDirectiveSyntax::ERROR_LINES_INVALID ? $parts['errorPart'] : null,
        ];
    }

    /**
     * @param list<\MarkupCarve\Carve\Node\Node> $nodes
     */
    protected function allTextLike(array $nodes): bool
    {
        return IncludeDirectiveSyntax::allTextLike($nodes);
    }

    protected function isTextLike(Node $node): bool
    {
        return IncludeDirectiveSyntax::isTextLike($node);
    }

    /**
     * @param list<\MarkupCarve\Carve\Node\Node> $nodes
     */
    protected function textLikeContent(array $nodes): string
    {
        return IncludeDirectiveSyntax::textLikeContent($nodes);
    }

    /**
     * The extent the run occupied in its own file, before the splice.
     *
     * @param list<\MarkupCarve\Carve\Node\Node> $run
     */
    protected function hostSpan(array $run): ?SourceSpan
    {
        $first = null;
        $last = null;
        foreach ($run as $node) {
            $pos = $node->getPos();
            if ($pos === null) {
                continue;
            }
            $first ??= $pos;
            $last = $pos;
        }
        if ($first === null || $last === null) {
            return null;
        }

        return new SourceSpan(
            $first->startLine,
            $last->endLine,
            $first->startColumn,
            $last->endColumn,
            $first->startOffset,
            $last->endOffset,
            $first->file,
        );
    }

    /**
     * Join the adjacent text nodes the splice left behind (PART 12 §1a).
     *
     * A run assembled from more than one file takes the host's span rather than
     * none: its pieces are contiguous in no single file, so the contiguity rule
     * refuses them, and a host that mapped the text before expansion would lose
     * the mapping. A run still entirely from one file keeps that rule.
     *
     * @param list<\MarkupCarve\Carve\Node\Node> $nodes
     * @param \MarkupCarve\Carve\Ast\SourceSpan|null $host
     *
     * @return list<\MarkupCarve\Carve\Node\Node>
     */
    protected function mergeExpandedRun(array $nodes, ?SourceSpan $host): array
    {
        $out = [];
        $runStart = null;
        $foreign = false;
        foreach ($nodes as $node) {
            if (!$node instanceof Text) {
                $runStart = null;
                $foreign = false;
                $out[] = $node;

                continue;
            }
            $pos = $node->getPos();
            if ($runStart === null) {
                $runStart = $node;
                $foreign = $pos !== null && $host !== null && $pos->file !== $host->file;
                $out[] = $node;

                continue;
            }
            if ($pos !== null && $host !== null && $pos->file !== $host->file) {
                $foreign = true;
            }
            $runStart->setPos($foreign ? $host : TextRunCoalescer::mergedPos($runStart->getPos(), $pos));
            $runStart->appendContent($node->getContent());
        }

        return $out;
    }

    /**
     * The span of `$length` bytes of `$text` starting at `$fromByte`, measured
     * inside `$span`.
     *
     * A slice of a text node is a slice of the source, so it gets the part of
     * the span it covers rather than the whole one - PART 12 §4 rates a span
     * that selects the wrong text worse than no span at all. Offsets and
     * columns count codepoints while the slice boundaries are bytes, and a
     * node crossing a line break has no column arithmetic here, so either
     * gives up the span instead of inventing one.
     */
    protected function narrowedSpan(?SourceSpan $span, string $text, int $fromByte, int $length): ?SourceSpan
    {
        if ($span === null || $span->startLine !== $span->endLine || str_contains($text, "\n")) {
            return null;
        }

        $before = mb_strlen(substr($text, 0, $fromByte), 'UTF-8');
        $width = mb_strlen(substr($text, $fromByte, $length), 'UTF-8');
        $start = $span->startOffset + $before;

        return new SourceSpan(
            $span->startLine,
            $span->endLine,
            $span->startColumn + $before,
            $span->startColumn + $before + $width,
            $start,
            $start + $width,
            $span->file,
        );
    }

    /**
     * Record which file a position is measured in, for every node of a resolved
     * child (spec §19, source mapping).
     *
     * Runs AFTER the child's own includes are expanded and only where no
     * identity is set yet, so a grandchild keeps the file IT came from rather
     * than being overwritten by the file that pulled its parent in.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param string $file
     * @param list<array{file: string, line: int, column: int}> $reach
     */
    protected function stampSourceFile(Node $node, string $file, array $reach): void
    {
        foreach ($node->getChildren() as $child) {
            $pos = $child->getPos();
            if ($pos !== null && $pos->file === null) {
                $stamped = $pos->withFile($file);
                $child->setPos($stamped);
                self::$positionIncludeSites ??= new WeakMap();
                self::$positionIncludeSites[$stamped] = $reach;
            }
            $this->stampSourceFile($child, $file, $reach);
        }
    }

    /**
     * Restore positions parsed from an @lines slice to the coordinates of the
     * complete source. This runs before nested includes are expanded, so only
     * nodes belonging to this file receive its slice base.
     */
    protected function shiftSourcePositions(Node $node, string $completeSource, string $slice, int $lineBase): void
    {
        foreach ($node->getChildren() as $child) {
            $pos = $child->getPos();
            if ($pos !== null) {
                $child->setPos(new SourceSpan(
                    $pos->startLine + $lineBase,
                    $pos->endLine + $lineBase,
                    $pos->startColumn,
                    $pos->endColumn,
                    $this->sourceOffset($completeSource, $slice, $pos->startLine, $pos->startOffset, $lineBase),
                    $this->sourceOffset($completeSource, $slice, $pos->endLine, $pos->endOffset, $lineBase),
                    $pos->file,
                ));
            }
            $this->shiftSourcePositions($child, $completeSource, $slice, $lineBase);
        }
    }

    /**
     * Offset in the complete source for an offset parsed from a line slice.
     * A slice normalizes every physical ending to LF, so its offset cannot be
     * restored with a single base when the original uses CRLF.
     */
    protected function sourceOffset(string $completeSource, string $slice, int $sliceLine, int $offset, int $lineBase): int
    {
        return $this->lineStartOffset($completeSource, $sliceLine + $lineBase)
            + $offset
            - $this->lineStartOffset($slice, $sliceLine);
    }

    /**
     * Whether any node in the document has a position, which is how a host's
     * opt-in reaches this pass: the flag itself lives on the parser.
     */
    protected function carriesPositions(Node $node): bool
    {
        foreach ($node->getChildren() as $child) {
            if ($child->getPos() !== null || $this->carriesPositions($child)) {
                return true;
            }
        }

        return false;
    }

    protected function spentMessage(string $rule, string $path): string
    {
        if ($rule === self::RULE_CALL_LIMIT) {
            return "Include resolver call limit exceeded for '{$path}'";
        }

        return "Include size budget exceeded for '{$path}'";
    }

    /**
     * @throws \InvalidArgumentException
     *
     * @return array{ok: true, start: int, end: int}|array{ok: false, rule: 'include-region-invalid'}
     */
    public static function selectIncludeRegion(string $source, string $name, string $mode): array
    {
        if ($mode !== 'carve' && $mode !== 'code') {
            throw new InvalidArgumentException('Include region mode must be carve or code');
        }

        $range = self::regionRange($source, $name, $mode === 'code');

        return $range === null
            ? ['ok' => false, 'rule' => self::RULE_REGION_INVALID]
            : ['ok' => true, 'start' => $range[0], 'end' => $range[1]];
    }

    /**
     * @return array{int, int}|null
     */
    protected static function regionRange(string $source, string $selected, bool $code): ?array
    {
        // I1b: the file's leading U+FEFF is not content, so it cannot hide a line-1 marker.
        if (str_starts_with($source, "\u{FEFF}")) {
            $source = substr($source, 3);
        }
        $lines = preg_split('/\r\n|\n|\r/', $source) ?: [];
        $stack = [];
        $seen = [];
        $range = null;
        foreach ($lines as $index => $line) {
            $line = ltrim($line, " \t");
            $closer = '';
            if (str_starts_with($line, '%%') && !str_starts_with($line, '%%%')) {
                $body = substr($line, 2);
            } elseif (!$code) {
                continue;
            } elseif (str_starts_with($line, '//') || str_starts_with($line, '--')) {
                $body = substr($line, 2);
            } elseif (str_starts_with($line, '/*')) {
                $body = substr($line, 2);
                $closer = '\*\/';
            } elseif (str_starts_with($line, '<!--')) {
                $body = substr($line, 4);
                $closer = '-->';
            } else {
                $body = $line;
            }
            $body = ltrim($body, " \t");
            if (preg_match('/^#(?:end)?region(?![A-Za-z0-9_])/', $body) !== 1) {
                continue;
            }
            if (preg_match('/^#(end)?region(?:[ \t]+([A-Za-z_][A-Za-z0-9_-]*))?[ \t]*' . $closer . '[ \t]*$/', $body, $marker) !== 1) {
                return null;
            }
            $name = $marker[2] ?? null;
            if (($marker[1] ?? '') === '') {
                if ($name === null || isset($seen[$name])) {
                    return null;
                }
                $seen[$name] = true;
                $stack[] = [$name, $index + 1];
            } else {
                $open = array_pop($stack);
                if ($open === null || ($name !== null && $name !== $open[0])) {
                    return null;
                }
                if ($open[0] === $selected) {
                    $range = [$open[1] + 1, $index];
                }
            }
        }

        return $stack === [] ? $range : null;
    }

    /**
     * Physical lines in a file; a final line ending does not begin another one.
     */
    protected function lineCount(string $source): int
    {
        // A leading U+FEFF is not content, so a file holding only one has no lines.
        if (str_starts_with($source, "\u{FEFF}")) {
            $source = substr($source, 3);
        }
        $lines = preg_split('/\r\n|\n|\r/', $source) ?: [];
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return count($lines);
    }

    protected function sliceLines(string $source, int $start, int $end): string
    {
        if ($start > $end) {
            return '';
        }

        $lines = preg_split('/\r\n|\n|\r/', $source) ?: [];

        return implode("\n", array_slice($lines, max(0, $start - 1), $end - $start + 1));
    }

    /**
     * Codepoint offset at which a 1-based physical line starts in raw source.
     */
    protected function lineStartOffset(string $source, int $line): int
    {
        if ($line <= 1) {
            return 0;
        }

        preg_match_all('/\r\n|\n|\r/', $source, $matches, PREG_OFFSET_CAPTURE);
        $ending = $matches[0][$line - 2] ?? null;
        $prefixBytes = $ending === null
            ? strlen($source)
            : $ending[1] + strlen($ending[0]);

        return mb_strlen(substr($source, 0, $prefixBytes), 'UTF-8');
    }

    /**
     * The fragment `#name` selects from a child (spec I1a), or null. A heading
     * whose id (explicit or auto slug) is the name selects its section, bounded
     * by the block sequence it stands in; otherwise the first block carrying the
     * explicit id selects itself. Within each step the first match in document
     * order wins, a container before its contents.
     */
    protected function selectSection(Document $document, string $section): ?Document
    {
        // Slugs are deduplicated over every heading in document order, the
        // ones a selection cannot reach (inside a footnote) included.
        $tracker = new HeadingIdTracker();
        $headingIds = [];
        foreach ($this->collect($document, Heading::class) as $heading) {
            $headingIds[spl_object_id($heading)] = $tracker->getIdForHeading($heading);
        }

        $heading = $this->firstSelectable(
            $document,
            fn (Node $node): bool => $node instanceof Heading
                && ($headingIds[spl_object_id($node)] ?? null) === $section,
        );
        if ($heading instanceof Heading) {
            $siblings = array_values($heading->getParent()?->getChildren() ?? [$heading]);
            $start = (int)array_search($heading, $siblings, true);
            $end = count($siblings);
            for ($i = $start + 1; $i < $end; $i++) {
                $sibling = $siblings[$i];
                if ($sibling instanceof Heading && $sibling->getLevel() <= $heading->getLevel()) {
                    $end = $i;

                    break;
                }
            }

            return $this->documentOf(array_slice($siblings, $start, $end - $start));
        }

        $block = $this->firstSelectable(
            $document,
            fn (Node $node): bool => !$node instanceof Heading
                && !$this->isDefinitionBlock($node)
                && $node->getAttribute('id') === $section,
        );

        return $block === null ? null : $this->documentOf([$block]);
    }

    /**
     * The first node standing in a block sequence of $node, at any depth and in
     * document order, that passes $test. A list item, a table row or cell, a
     * definition term or description and a caption are never candidates
     * themselves; a footnote definition is never entered.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param \Closure(\MarkupCarve\Carve\Node\Node): bool $test
     *
     * @return \MarkupCarve\Carve\Node\Node|null
     */
    protected function firstSelectable(Node $node, Closure $test): ?Node
    {
        foreach ($node->getChildren() as $child) {
            $isPart = $child instanceof ListItem
                || $child instanceof TableRow
                || $child instanceof TableCell
                || $child instanceof DefinitionTerm
                || $child instanceof DefinitionDescription
                || $child instanceof Caption;
            // A block image stands in a block sequence as an Image node.
            $isBlock = $child instanceof BlockNode || $child instanceof Image;
            if (!$isPart && $isBlock && $test($child)) {
                return $child;
            }
            if (!$this->holdsBlocks($child)) {
                continue;
            }
            $hit = $this->firstSelectable($child, $test);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    protected function holdsBlocks(Node $node): bool
    {
        if (!$node instanceof BlockNode || $node instanceof Footnote) {
            return false;
        }
        if ($node instanceof TableCell) {
            return $node->hasBlockContent();
        }

        return !$node instanceof Paragraph
            && !$node instanceof Heading
            && !$node instanceof LineBlock
            && !$node instanceof Caption
            && !$node instanceof DefinitionTerm
            && !$node instanceof ContentNodeInterface;
    }

    /**
     * Definitions, comments and frontmatter carry no selectable content.
     */
    protected function isDefinitionBlock(Node $node): bool
    {
        return $node instanceof Footnote
            || $node instanceof LinkReferenceDefinition
            || $node instanceof AbbreviationDefinition
            || $node instanceof CitationDefinition
            || $node instanceof Comment
            || $node instanceof Frontmatter;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     */
    protected function documentOf(array $nodes): Document
    {
        $selected = new Document();
        $selected->setChildren(array_values($nodes));

        return $selected;
    }

    protected function shiftHeadings(Node $node, int $shift): void
    {
        if ($shift === 0) {
            return;
        }

        foreach ($node->getChildren() as $child) {
            if ($child instanceof Heading) {
                $original = $child->getLevel();
                $target = $original + $shift;
                $child->setLevel($target);
                if ($target < 1 || $target > 6) {
                    $this->warn("Included heading level clamped from {$target}", self::RULE_HEADING_CLAMP);
                }
            }

            $this->shiftHeadings($child, $shift);
        }
    }

    protected function autoShift(Document $document, bool $block, ?Node $contextParent, ?Node $contextNode): int
    {
        if (!$block || $contextParent === null || $contextNode === null) {
            return 0;
        }

        $minimumLevel = $this->minimumHeadingLevel($document);
        if ($minimumLevel === null) {
            return 0;
        }

        return ($this->contextHeadingLevel($contextParent, $contextNode) + 1) - $minimumLevel;
    }

    protected function minimumHeadingLevel(Node $node): ?int
    {
        $minimum = null;
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Heading) {
                $level = $child->getLevel();
                $minimum = $minimum === null ? $level : min($minimum, $level);
            }

            $childMinimum = $this->minimumHeadingLevel($child);
            if ($childMinimum !== null) {
                $minimum = $minimum === null ? $childMinimum : min($minimum, $childMinimum);
            }
        }

        return $minimum;
    }

    protected function contextHeadingLevel(Node $parent, Node $node): int
    {
        $level = $this->nearestDirectPrecedingHeadingLevel($parent, $node);
        if ($level !== null) {
            return $level;
        }

        $ancestor = $parent->getParent();
        if ($ancestor === null) {
            return 0;
        }

        return $this->contextHeadingLevel($ancestor, $parent);
    }

    protected function nearestDirectPrecedingHeadingLevel(Node $parent, Node $node): ?int
    {
        $level = null;
        foreach ($parent->getChildren() as $child) {
            if ($child === $node) {
                return $level;
            }

            if ($child instanceof Heading) {
                $level = $child->getLevel();
            }
        }

        return $level;
    }

    protected function resolveCollisions(Document $document): void
    {
        $this->resolveFootnoteCollisions($document);
        $this->resolveExplicitIdCollisions($document);
        $this->rebindFootnoteRefs($document);
        $this->collectIncludedFootnoteDefinitions($document);
    }

    /**
     * Move footnote definitions an include brought in to the end of the
     * document, which is where parsing the equivalent flat file puts them.
     *
     * A definition written mid-document is already collected to the end by an
     * ordinary parse in every engine. A merged child's definition never went
     * through that, so it stayed wherever the child had it - interleaved
     * between the parent's blocks - and the tree, and the Carve the writer
     * produced from it, disagreed with the same document written by hand.
     *
     * ONLY definitions carrying a file identity move: those are the ones an
     * include brought in. A definition the author wrote in THIS file keeps the
     * position it was authored at, which is a round-trip requirement of its own
     * (PART 11 section 1).
     *
     * @param \MarkupCarve\Carve\Node\Document $document
     *
     * @return void
     */
    protected function collectIncludedFootnoteDefinitions(Document $document): void
    {
        $included = [];
        foreach ($this->collect($document, Footnote::class) as $footnote) {
            if ($this->scopeOf($footnote) !== null) {
                $included[spl_object_id($footnote)] = $footnote;
            }
        }
        if ($included === []) {
            return;
        }

        $this->removeNodes($document, $included);

        $children = $document->getChildren();
        foreach ($included as $footnote) {
            $children[] = $footnote;
        }
        $document->setChildren($children);
    }

    /**
     * Detach the given nodes wherever they sit in the tree.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<int, \MarkupCarve\Carve\Node\Block\Footnote> $remove
     *
     * @return void
     */
    protected function removeNodes(Node $node, array $remove): void
    {
        $children = $node->getChildren();
        $kept = [];
        foreach ($children as $child) {
            if (isset($remove[spl_object_id($child)])) {
                continue;
            }
            $this->removeNodes($child, $remove);
            $kept[] = $child;
        }
        if (count($kept) !== count($children)) {
            $node->setChildren($kept);
        }
    }

    /**
     * Re-bind footnote references across the include boundary.
     *
     * A reference is marked unresolved at PARSE time, when only its own file is
     * in hand - so a child referring to a note the parent defines, or a parent
     * referring to one a child brings in, was frozen as literal text before the
     * two halves ever met. Footnotes are collected and numbered globally in the
     * ASSEMBLED document (I5), so the question is only answerable here.
     *
     * Runs after collision renaming, so it binds against the labels that
     * actually survived.
     *
     * @param \MarkupCarve\Carve\Node\Document $document
     *
     * @return void
     */
    protected function rebindFootnoteRefs(Document $document): void
    {
        $defined = [];
        foreach ($this->collect($document, Footnote::class) as $footnote) {
            $defined[$footnote->getLabel()] = true;
        }
        if ($defined === []) {
            return;
        }

        foreach ($this->collect($document, FootnoteRef::class) as $ref) {
            if ($ref->isUnresolved() && isset($defined[$ref->getLabel()])) {
                $ref->setUnresolved(false);
            }
        }
    }

    protected function resolveFootnoteCollisions(Document $document): void
    {
        $used = [];
        $footnotes = $this->collect($document, Footnote::class);
        foreach ($footnotes as $footnote) {
            if ($this->scopeOf($footnote) === null) {
                $used[$footnote->getLabel()] = true;
            }
        }
        foreach ($footnotes as $footnote) {
            $label = $footnote->getLabel();
            if ($this->scopeOf($footnote) === null) {
                continue;
            }
            if (!array_key_exists($label, $used)) {
                $used[$label] = true;

                continue;
            }

            $newLabel = $this->leastFree($label, $used);
            $footnote->setLabel($newLabel);
            $this->renameFootnoteRefs($document, $label, $newLabel, $this->scopeOf($footnote));
            $this->warn(
                "Duplicate footnote label '{$label}' renamed to '{$newLabel}'",
                self::RULE_FOOTNOTE_RENAME,
                $this->fileOf($footnote),
            );
        }
    }

    /**
     * Spec I5: explicit ids on every element share one namespace, compared
     * exactly. Inclusions are visited parent before child and earlier include
     * before later; an id is renamed when an earlier inclusion already holds
     * it. Auto-slug collisions stay with the render-time heading-id tracker
     * (spec section 13).
     */
    protected function resolveExplicitIdCollisions(Document $document): void
    {
        /** @var list<array{node: \MarkupCarve\Carve\Node\Node, scope: string}> $occurrences */
        $occurrences = [];
        $used = [];
        foreach ($this->collectWithSelf($document) as $node) {
            $id = $node->getAttribute('id');
            if ($id === null || $id === '') {
                continue;
            }
            $used[$id] = true;
            $occurrences[] = ['node' => $node, 'scope' => $this->scopeOf($node) ?? ''];
        }
        // Stable sort: document order is kept within one inclusion.
        usort(
            $occurrences,
            fn (array $a, array $b): int => ($this->scopeRank[$a['scope']] ?? 0) <=> ($this->scopeRank[$b['scope']] ?? 0),
        );

        /** @var array<string, array<string, true>> $holders */
        $holders = [];
        /** @var array<string, list<array{node: \MarkupCarve\Carve\Node\Node, old: string, new: string}>> $byScope */
        $byScope = [];
        $renamedScopes = [];
        foreach ($occurrences as ['node' => $node, 'scope' => $scope]) {
            $id = (string)$node->getAttribute('id');
            $earlier = $holders[$id] ?? [];
            unset($earlier[$scope]);
            if ($earlier === []) {
                $holders[$id][$scope] = true;
                $byScope[$scope][] = ['node' => $node, 'old' => $id, 'new' => $id];

                continue;
            }

            $newId = $this->leastFree($id, $used);
            $node->setAttribute('id', $newId);
            $byScope[$scope][] = ['node' => $node, 'old' => $id, 'new' => $newId];
            $renamedScopes[$scope] = true;
            $what = $node instanceof Heading ? 'heading id' : 'id';
            $this->warn(
                "Duplicate {$what} '{$id}' renamed to '{$newId}'",
                self::RULE_ID_RENAME,
                $this->fileOf($node),
            );
        }

        if ($renamedScopes === []) {
            return;
        }

        $nodes = $this->collectWithSelf($document);
        // Cross-reference targets per inclusion, in document order: explicit
        // ids on headings and captioned blocks, and auto heading slugs, which
        // see the file's explicit ids reserved first as the renderer does.
        $entryByNode = [];
        $trackers = [];
        foreach ($byScope as $scope => $entries) {
            if (!isset($renamedScopes[$scope])) {
                continue;
            }
            $trackers[$scope] = new HeadingIdTracker();
            foreach ($entries as $entry) {
                $entryByNode[spl_object_id($entry['node'])] = $entry;
                $trackers[$scope]->trackId($entry['old']);
            }
        }
        $targets = [];
        $captioned = [];
        foreach ($nodes as $node) {
            $scope = $this->scopeOf($node) ?? '';
            if (!isset($renamedScopes[$scope])) {
                continue;
            }
            $entry = $entryByNode[spl_object_id($node)] ?? null;
            if ($entry === null && $node instanceof Heading) {
                $slug = $trackers[$scope]->getIdForHeading($node);
                $targets[$scope][] = ['old' => $slug, 'new' => $slug];

                continue;
            }
            if ($entry !== null && $node instanceof Heading) {
                $targets[$scope][] = ['old' => $entry['old'], 'new' => $entry['new']];
            } elseif ($entry !== null && $this->hasNumberedCaption($node)) {
                $captioned[$scope][] = ['old' => $entry['old'], 'new' => $entry['new']];
            }
        }
        // The renderer registers heading ids before numbered captions.
        foreach ($captioned as $scope => $entries) {
            $targets[$scope] = [...$targets[$scope] ?? [], ...$entries];
        }
        foreach ($nodes as $node) {
            $scope = $this->scopeOf($node) ?? '';
            if (!isset($renamedScopes[$scope])) {
                continue;
            }
            $this->followRename($node, $byScope[$scope], $targets[$scope] ?? []);
        }
    }

    /**
     * Whether a `</#id>` can reach $node through its numbered caption.
     */
    protected function hasNumberedCaption(Node $node): bool
    {
        $caption = $node instanceof Table || $node instanceof FigureGroup ? $node->getCaption() : null;
        if ($node instanceof Figure) {
            foreach ($node->getChildren() as $child) {
                if ($child instanceof Caption) {
                    $caption = $child;
                }
            }
        }

        return $caption !== null && $this->collect($caption, CaptionNumber::class) !== [];
    }

    /**
     * Point a reference at its target's new id, when the target is the one the
     * reference reaches in its own file (spec I5, references follow the rename).
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param list<array{node: \MarkupCarve\Carve\Node\Node, old: string, new: string}> $ids
     * @param list<array{old: string, new: string}> $targets Cross-reference targets in the same inclusion.
     */
    protected function followRename(Node $node, array $ids, array $targets): void
    {
        if ($node instanceof HeadingRef) {
            $target = $node->getTargetId();
            // Exact, as the renderer resolves it (CARVE-P9R-010).
            $hit = null;
            foreach ($targets as $entry) {
                if ($entry['old'] === $target) {
                    $hit = $entry;

                    break;
                }
            }
            if ($hit !== null && $hit['new'] !== $hit['old']) {
                $node->setTargetId($hit['new']);
            }

            return;
        }

        $destination = match (true) {
            $node instanceof Link => $node->getDestination(),
            $node instanceof Image => $node->getSource(),
            default => null,
        };
        if ($destination === null || !str_starts_with($destination, '#')) {
            return;
        }
        $fragment = substr($destination, 1);
        foreach ($ids as $entry) {
            if ($entry['old'] !== $fragment) {
                continue;
            }
            if ($entry['new'] === $entry['old']) {
                return;
            }
            if ($node instanceof Link) {
                $node->setDestination('#' . $entry['new']);
            } elseif ($node instanceof Image) {
                $node->resolveReference('#' . $entry['new'], $node->getTitle());
            }

            return;
        }
    }

    /**
     * A table's and a figure group's caption is kept beside the children, not
     * among them.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     *
     * @return array<\MarkupCarve\Carve\Node\Node>
     */
    protected function childrenWithCaption(Node $node): array
    {
        $children = $node->getChildren();
        $caption = $node instanceof Table || $node instanceof FigureGroup ? $node->getCaption() : null;
        if ($caption !== null && !in_array($caption, $children, true)) {
            $children[] = $caption;
        }

        return $children;
    }

    /**
     * Every descendant of $node in document order (pre-order).
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     *
     * @return list<\MarkupCarve\Carve\Node\Node>
     */
    protected function collectWithSelf(Node $node): array
    {
        $out = [];
        foreach ($this->childrenWithCaption($node) as $child) {
            $out[] = $child;
            foreach ($this->collectWithSelf($child) as $descendant) {
                $out[] = $descendant;
            }
        }

        return $out;
    }

    protected function markScope(Node $node, string $scope): void
    {
        $this->scopeByObjectId[spl_object_id($node)] = $scope;
        foreach ($this->childrenWithCaption($node) as $child) {
            $this->markScope($child, $scope);
        }
    }

    protected function scopeOf(Node $node): ?string
    {
        return $this->scopeByObjectId[spl_object_id($node)] ?? null;
    }

    /**
     * @template T of \MarkupCarve\Carve\Node\Node
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    protected function collect(Node $node, string $class): array
    {
        $matches = [];
        foreach ($node->getChildren() as $child) {
            if ($child instanceof $class) {
                $matches[] = $child;
            }
            $matches = [...$matches, ...$this->collect($child, $class)];
        }

        return $matches;
    }

    /**
     * @param string $base
     * @param array<string, true> $used
     */
    protected function leastFree(string $base, array &$used): string
    {
        $suffix = 2;
        do {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        } while (array_key_exists($candidate, $used));

        $used[$candidate] = true;

        return $candidate;
    }

    protected function renameFootnoteRefs(Node $node, string $old, string $new, ?string $scope): void
    {
        foreach ($node->getChildren() as $child) {
            if ($child instanceof FootnoteRef && $child->getLabel() === $old && $this->scopeOf($child) === $scope) {
                $child->setLabel($new);
            }
            $this->renameFootnoteRefs($child, $old, $new, $scope);
        }
    }

    /**
     * @param string $message
     * @param string|null $rule Stable cross-engine rule id for the condition
     *   (e.g. `include-unresolved`); see the RULE_* constants.
     * @param string|null $file Overrides the current file cursor, for warnings
     *   raised outside the expansion walk (the collision pass, which runs once
     *   over the assembled document and recovers the file from the node scope).
     * @param string|null $detail Untrusted supplementary text kept off the
     *   rendered message; see ParseWarning.
     */
    protected function warn(string $message, ?string $rule = null, ?string $file = null, ?string $detail = null): void
    {
        // A rule not yet represented is always kept, so a capped report still
        // shows every distinct failure class; only repeats of a class already
        // shown are counted instead of stored.
        $key = $rule ?? '';
        if (count($this->warnings) >= $this->warningLimit && isset($this->seenRules[$key])) {
            $this->suppressedWarnings++;

            return;
        }

        $this->seenRules[$key] = true;
        $this->warnings[] = new ParseWarning($message, $this->warningSite['line'], $this->warningSite['column'], 'include', null, $file ?? $this->warningFile, $detail, $rule, $this->includeChain === [] ? null : $this->includeChain);
    }

    /**
     * Include warnings raised but not retained, once the warning limit was
     * reached. Zero on every uncapped run; non-zero means getWarnings() is a
     * sample rather than the whole report.
     */
    public function getSuppressedWarnings(): int
    {
        return $this->suppressedWarnings;
    }

    /**
     * File identity for a node in the assembled document: the file the node's
     * include scope came from, or the top-level document when the node is not
     * inside any include. Null when the top level has no path - never a
     * fabricated one.
     */
    protected function fileOf(Node $node): ?string
    {
        $scope = $this->scopeOf($node);
        if ($scope === null) {
            return $this->currentPath;
        }

        return $this->fileByScope[$scope] ?? null;
    }

    /**
     * A successful READ always wins: a target first seen unresolved - a missing
     * file included twice, the second time after it appeared - is upgraded, and
     * a target already read is never downgraded by a later refusal. Re-recording
     * an existing key keeps its original position, so the set stays in
     * first-encounter order.
     */
    protected function recordDependency(string $target, bool $resolved): void
    {
        if (!$resolved && array_key_exists($target, $this->dependencies)) {
            return;
        }

        $this->dependencies[$target] = new IncludeDependency($target, $resolved);
    }
}
