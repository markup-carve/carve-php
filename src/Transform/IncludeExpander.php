<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Transform;

use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\Ast\TextRunCoalescer;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\ParseWarning;
use MarkupCarve\Carve\Exception\UnresolvedIncludeException;
use MarkupCarve\Carve\Extension\Frontmatter;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\HeadingRef;
use MarkupCarve\Carve\Node\Inline\InlineNode;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;
use Throwable;

/**
 * Expands processor-level {{ ... }} include directives in parsed text nodes.
 */
class IncludeExpander implements TransformerInterface
{
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
     * directive - measured at roughly 124 MB for a megabyte of them. One
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
    public const RULE_HEADING_ID_RENAME = 'include-heading-id-rename';

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
    public const RULE_CALL_LIMIT = 'include-call-limit';

    /**
     * @var list<\MarkupCarve\Carve\Exception\ParseWarning>
     */
    protected array $warnings = [];

    protected int $bytesUsed = 0;

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
        $this->resolverCalls = 0;
        $this->resourcesSpent = null;
        $this->suppressedWarnings = 0;
        $this->seenRules = [];
        $this->trackPositions = $this->carriesPositions($document);
        $this->scopeByObjectId = [];
        $this->dependencies = [];
        $this->scopeSeq = 0;
        $this->fileByScope = [];
        $this->warningFile = $this->currentPath;

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
            $directive = $this->parseDirective($content);
            if ($directive !== null) {
                $replacement = $this->resolveDirective($directive, true, $currentPath, $stack, $depth, $budget, $parent, $paragraph);
                if ($replacement !== null) {
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

        if (preg_match_all(IncludeDirectiveSyntax::SCAN, $full, $matches, PREG_OFFSET_CAPTURE) === false) {
            return false;
        }

        $spans = [];
        foreach ($matches[0] as $match) {
            [$literal, $offset] = $match;
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
            $nodes = [...$nodes, ...$this->sliceRun($run, $cursor, $span['start'])];
            $nodes = [...$nodes, ...$span['nodes']];
            $cursor = $span['end'];
        }
        $nodes = [...$nodes, ...$this->sliceRun($run, $cursor, strlen($full))];
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
     *
     * @return list<\MarkupCarve\Carve\Node\Node>
     */
    protected function sliceRun(array $run, int $from, int $to): array
    {
        $out = [];
        $offset = 0;
        foreach ($run as $node) {
            $text = $this->textLikeContent([$node]);
            $start = $offset;
            $end = $offset + strlen($text);
            $offset = $end;
            if ($end <= $from || $start >= $to) {
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
     * @param array{literal: string, path: string, section: string|null, lines: array{start: int, end: int}|null, shift: int|'auto'} $directive
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
        if ($directive['section'] !== null && $directive['lines'] !== null) {
            $this->warn('Include directive cannot combine #section and @lines', self::RULE_SELECTION_CONFLICT);

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

        $sliceLineBase = 0;
        $completeSource = $source;
        if ($directive['lines'] !== null) {
            $sliceLineBase = $directive['lines']['start'] - 1;
            $source = $this->sliceLines($source, $directive['lines']['start'], $directive['lines']['end']);
        }

        $document = $this->parseChild($source);
        if ($directive['lines'] !== null) {
            $this->shiftSourcePositions($document, $completeSource, $source, $sliceLineBase);
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

        $scope = $directive['path'] . '#' . (++$this->scopeSeq);
        $this->markScope($document, $scope);
        $this->fileByScope[$scope] = $id;
        // Everything from here to the restore below operates on the child's own
        // content, so a warning it raises (a heading clamp, a nested cycle)
        // names the child rather than the document that included it.
        $outerFile = $this->warningFile;
        $this->warningFile = $id;
        $this->expandChildren($document, $id, [...$stack, $id], $depth + 1, $budget);
        $this->stampSourceFile($document, $id);
        $shift = $directive['shift'] === 'auto'
            ? $this->autoShift($document, $block, $contextParent, $contextNode)
            : $directive['shift'];
        $this->shiftHeadings($document, $shift);
        // Back in the parent: the remaining checks are about the DIRECTIVE, not
        // the child's content, so they are the including document's problem.
        $this->warningFile = $outerFile;

        $nodes = array_values($document->getChildren());
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

        return null;
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
     * @return array{literal: string, path: string, section: string|null, lines: array{start: int, end: int}|null, shift: int|'auto'}|null
     */
    protected function parseDirective(string $text): ?array
    {
        $parts = IncludeDirectiveSyntax::parse($text);
        if ($parts === null) {
            return null;
        }

        if ($parts['error'] !== null) {
            // A directive-shaped run whose options are wrong is worth telling
            // the author about; a run that is not directive-shaped at all is
            // just text and stays silent.
            if ($parts['error'] === IncludeDirectiveSyntax::ERROR_UNKNOWN_OPTION) {
                $this->warn("Unknown include option '{$parts['errorPart']}'", self::RULE_UNKNOWN_OPTION);
            }

            return null;
        }

        return [
            'literal' => $text,
            'path' => $parts['path'],
            'section' => $parts['section'],
            'lines' => $parts['lines'],
            'shift' => $parts['shift'],
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
     */
    protected function stampSourceFile(Node $node, string $file): void
    {
        foreach ($node->getChildren() as $child) {
            $pos = $child->getPos();
            if ($pos !== null && $pos->file === null) {
                $child->setPos($pos->withFile($file));
            }
            $this->stampSourceFile($child, $file);
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

    protected function selectSection(Document $document, string $section): ?Document
    {
        $children = array_values($document->getChildren());
        $tracker = new HeadingIdTracker();
        $start = null;
        $level = null;

        foreach ($children as $index => $child) {
            $headings = $child instanceof Heading ? [$child] : [];
            $headings = [...$headings, ...$this->collect($child, Heading::class)];
            foreach ($headings as $heading) {
                $id = $tracker->getIdForHeading($heading);
                if ($child === $heading && $id === $section) {
                    $start = (int)$index;
                    $level = $heading->getLevel();

                    break 2;
                }
            }
        }

        if ($start === null || $level === null) {
            return null;
        }

        $selected = new Document();

        $end = count($children);
        $count = count($children);
        for ($i = $start + 1; $i < $count; $i++) {
            $child = $children[$i];
            if ($child instanceof Heading && $child->getLevel() <= $level) {
                $end = $i;

                break;
            }
        }

        foreach (array_slice($children, $start, $end - $start) as $child) {
            $selected->appendChild($child);
        }

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
        $this->resolveExplicitHeadingCollisions($document);
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
     * Spec I5 scopes the include-time rename to explicit ids only: auto-slug
     * collisions stay with the render-time heading-id tracker (spec section 13),
     * which suffixes duplicates once the files are merged.
     */
    protected function resolveExplicitHeadingCollisions(Document $document): void
    {
        $used = [];
        $headings = $this->collect($document, Heading::class);
        foreach ($headings as $heading) {
            $id = $heading->getAttribute('id');
            if ($id !== null && $id !== '' && $this->scopeOf($heading) === null) {
                $used[$id] = true;
            }
        }
        foreach ($headings as $heading) {
            $id = $heading->getAttribute('id');
            if ($id === null || $id === '' || $this->scopeOf($heading) === null) {
                continue;
            }

            if (!array_key_exists($id, $used)) {
                $used[$id] = true;

                continue;
            }

            $newId = $this->leastFree($id, $used);
            $heading->setAttribute('id', $newId);
            $this->renameHeadingRefs($document, $id, $newId, $this->scopeOf($heading));
            $this->warn(
                "Duplicate heading id '{$id}' renamed to '{$newId}'",
                self::RULE_HEADING_ID_RENAME,
                $this->fileOf($heading),
            );
        }
    }

    protected function markScope(Node $node, string $scope): void
    {
        $this->scopeByObjectId[spl_object_id($node)] = $scope;
        foreach ($node->getChildren() as $child) {
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

    protected function renameHeadingRefs(Node $node, string $old, string $new, ?string $scope): void
    {
        foreach ($node->getChildren() as $child) {
            if ($child instanceof HeadingRef && $child->getTargetId() === $old && $this->scopeOf($child) === $scope) {
                $child->setTargetId($new);
            }
            $this->renameHeadingRefs($child, $old, $new, $scope);
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
        $this->warnings[] = new ParseWarning($message, 1, 1, 'include', null, $file ?? $this->warningFile, $detail, $rule);
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
