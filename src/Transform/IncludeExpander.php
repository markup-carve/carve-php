<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Transform;

use MarkupCarve\Carve\Exception\ParseWarning;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\HeadingRef;
use MarkupCarve\Carve\Node\Inline\Mention;
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
     * @return list<\MarkupCarve\Carve\Transform\IncludeDependency>
     */
    public function getDependencies(): array
    {
        ksort($this->dependencies);

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
            $this->warn('Include directive cannot combine #section and @lines');

            return null;
        }

        if ($depth >= $this->depthLimit) {
            $this->recordDependency($directive['path'], false);
            $this->warn("Include depth limit exceeded for '{$directive['path']}'");

            return null;
        }

        try {
            $resolved = $this->resolver?->resolve(
                $directive['path'],
                new IncludeContext($currentPath, $currentPath, $stack, $depth),
            );
        } catch (Throwable $exception) {
            $this->recordDependency($directive['path'], false);
            $this->warn($exception->getMessage());

            return null;
        }

        if ($resolved === null) {
            $this->recordDependency($directive['path'], false);
            $this->warn("Include could not be resolved: {$directive['path']}");

            return null;
        }

        $source = $resolved instanceof ResolvedInclude ? $resolved->getSource() : $resolved;
        $id = $resolved instanceof ResolvedInclude ? ($resolved->getId() ?? $directive['path']) : $directive['path'];
        $this->recordDependency($id, true);
        // The cycle guard compares canonical ids after resolution, so a resolver
        // that supplies ids catches 'b.crv' vs './b.crv' spellings of one file.
        if (in_array($id, $stack, true)) {
            $this->recordDependency($id, false);
            $this->warn("Include cycle detected for '{$directive['path']}'");

            return null;
        }

        if (str_contains($source, "\0") || preg_match('//u', $source) !== 1) {
            $this->recordDependency($id, false);
            $this->warn("Include target is binary or non-text: {$directive['path']}");

            return null;
        }

        $bytes = strlen($source);
        if ($this->bytesUsed + $bytes > $budget) {
            $this->recordDependency($id, false);
            $this->warn("Include size budget exceeded for '{$directive['path']}'");

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
                // The file was read, but the include did not expand, so the
                // dependency is attempted-but-unresolved: a host must still
                // watch the target and must not treat the include as having
                // succeeded.
                $this->recordDependency($id, false);
                $this->warn("Include has no section '#{$directive['section']}': {$directive['path']}");

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

        $this->warn("Inline include resolved to block content for '{$directive['path']}'");

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
        if (!preg_match('/^\{\{ (.+) \}\}$/s', $text, $match)) {
            return null;
        }

        $body = $match[1];
        // The core's smart-quotes pass rewrites "..." before this pass sees
        // the text, so a quoted path arrives with typographic quotes.
        if (str_starts_with($body, '"') || str_starts_with($body, "\u{201c}")) {
            $pattern = str_starts_with($body, '"')
                ? '/^"((?:\\\\.|[^"\\\\])*)"(.*)$/s'
                : '/^\x{201c}([^\x{201d}]*)\x{201d}(.*)$/su';
            if (!preg_match($pattern, $body, $pathMatch)) {
                return null;
            }
            $path = stripcslashes($pathMatch[1]);
            $rest = trim($pathMatch[2]);
        } else {
            if (!preg_match('/^([^#@} "]+)(.*)$/s', $body, $pathMatch)) {
                return null;
            }
            $path = $pathMatch[1];
            $rest = trim($pathMatch[2]);
        }

        $section = null;
        $lines = null;
        $shift = 0;
        if ($rest !== '') {
            foreach (preg_split('/\s+/', $rest) ?: [] as $part) {
                if (preg_match('/^#([A-Za-z_][A-Za-z0-9_-]*)$/', $part, $sectionMatch)) {
                    $section = $sectionMatch[1];

                    continue;
                }

                if (preg_match('/^@lines:(\d+)-(\d+)$/', $part, $lineMatch)) {
                    $lines = ['start' => (int)$lineMatch[1], 'end' => (int)$lineMatch[2]];

                    continue;
                }

                if (preg_match('/^@shift:([+-]?\d+)$/', $part, $shiftMatch)) {
                    $shift = (int)$shiftMatch[1];

                    continue;
                }

                if ($part === '@shift:auto') {
                    $shift = 'auto';

                    continue;
                }

                if (str_starts_with($part, '@')) {
                    $this->warn("Unknown include option '{$part}'");

                    return null;
                }

                return null;
            }
        }

        return [
            'literal' => $text,
            'path' => $path,
            'section' => $section,
            'lines' => $lines,
            'shift' => $shift,
        ];
    }

    /**
     * @param list<\MarkupCarve\Carve\Node\Node> $nodes
     */
    protected function allTextLike(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (!$this->isTextLike($node)) {
                return false;
            }
        }

        return true;
    }

    protected function isTextLike(Node $node): bool
    {
        return $node instanceof Text || $node instanceof EscapedText || $node instanceof Mention;
    }

    /**
     * @param list<\MarkupCarve\Carve\Node\Node> $nodes
     */
    protected function textLikeContent(array $nodes): string
    {
        $content = '';
        foreach ($nodes as $node) {
            if ($node instanceof Text || $node instanceof EscapedText) {
                $content .= $node->getContent();
            } elseif ($node instanceof Mention) {
                $content .= $this->textLikeContent(array_values($node->getChildren()));
            }
        }

        return $content;
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
                    $this->warn("Included heading level clamped from {$target}");
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
            $this->warn("Duplicate footnote label '{$label}' renamed to '{$newLabel}'", $this->fileOf($footnote));
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
            $this->warn("Duplicate heading id '{$id}' renamed to '{$newId}'", $this->fileOf($heading));
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
     * @param string|null $file Overrides the current file cursor, for warnings
     *   raised outside the expansion walk (the collision pass, which runs once
     *   over the assembled document and recovers the file from the node scope).
     */
    protected function warn(string $message, ?string $file = null): void
    {
        $this->warnings[] = new ParseWarning($message, 1, 1, 'include', null, $file ?? $this->warningFile);
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

    protected function recordDependency(string $target, bool $resolved): void
    {
        if ($resolved && array_key_exists($target, $this->dependencies) && !$this->dependencies[$target]->isResolved()) {
            return;
        }

        $this->dependencies[$target] = new IncludeDependency($target, $resolved);
    }
}
