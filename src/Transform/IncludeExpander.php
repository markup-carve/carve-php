<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Transform;

use MarkupCarve\Carve\Exception\ParseWarning;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\HeadingRef;
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
     * @var string
     */
    private const DIRECTIVE_SCAN = '/\{\{ [^{}]*? \}\}/s';

    /**
     * Loose directive shape: one whole-paragraph token, valid options or not.
     *
     * @var string
     */
    private const DIRECTIVE_SHAPE = '/^\{\{[^{}]*\}\}$/s';

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
     * @var list<\MarkupCarve\Carve\Exception\ParseWarning>
     */
    protected array $warnings = [];

    protected int $bytesUsed = 0;

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
     * @param \MarkupCarve\Carve\Transform\IncludeResolverInterface|null $resolver
     * @param string|null $currentPath
     * @param int $depthLimit
     * @param int|null $byteBudget
     * @param string|null $source Parsed source of the document, when the host
     *   still has it. Supplying it lets the pass skip its AST walk entirely
     *   for documents that cannot contain a directive.
     */
    public function __construct(
        protected ?IncludeResolverInterface $resolver = null,
        protected ?string $currentPath = null,
        protected int $depthLimit = self::DEFAULT_DEPTH_LIMIT,
        protected ?int $byteBudget = null,
        protected ?string $source = null,
    ) {
    }

    public function transform(Document $document): Document
    {
        $this->warnings = [];
        $this->bytesUsed = 0;
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

            $this->expandChildren($child, $currentPath, $stack, $depth, $budget);
        }
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
            if (preg_match(self::DIRECTIVE_SHAPE, trim($content)) === 1) {
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
                $children = array_values($node->getChildren());
                $count = count($children);
                $i = 0;

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

        if (preg_match_all(self::DIRECTIVE_SCAN, $full, $matches, PREG_OFFSET_CAPTURE) === false) {
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

            $value = substr($text, max($from, $start) - $start, min($to, $end) - max($from, $start));
            if ($value === $text) {
                $out[] = $node;
            } elseif ($value !== '') {
                $out[] = new Text($value);
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

        try {
            $resolved = $this->resolver?->resolve(
                $directive['path'],
                new IncludeContext($currentPath, $currentPath, $stack, $depth),
            );
        } catch (Throwable $exception) {
            $this->recordDependency($directive['path'], false);
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

        $bytes = strlen($source);
        if ($this->bytesUsed + $bytes > $budget) {
            $this->warn("Include size budget exceeded for '{$directive['path']}'", self::RULE_BUDGET);

            return null;
        }
        $this->bytesUsed += $bytes;

        if ($directive['lines'] !== null) {
            $source = $this->sliceLines($source, $directive['lines']['start'], $directive['lines']['end']);
        }

        $document = (new BlockParser())->parse($source);
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

    protected function sliceLines(string $source, int $start, int $end): string
    {
        if ($start > $end) {
            return '';
        }

        $lines = preg_split('/\r\n|\n|\r/', $source) ?: [];

        return implode("\n", array_slice($lines, max(0, $start - 1), $end - $start + 1));
    }

    protected function selectSection(Document $document, string $section): ?Document
    {
        $children = array_values($document->getChildren());
        $tracker = new HeadingIdTracker();
        $start = null;
        $level = null;

        foreach ($children as $index => $child) {
            if (!$child instanceof Heading) {
                continue;
            }

            $id = $tracker->getIdForHeading($child);
            if ($id === $section) {
                $start = (int)$index;
                $level = $child->getLevel();

                break;
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
        $this->warnings[] = new ParseWarning($message, 1, 1, 'include', null, $file ?? $this->warningFile, $detail, $rule);
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
