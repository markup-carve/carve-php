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
     * @var list<\MarkupCarve\Carve\Exception\ParseWarning>
     */
    protected array $warnings = [];

    protected int $bytesUsed = 0;

    /**
     * @var array<int, string>
     */
    protected array $scopeByObjectId = [];

    protected int $scopeSeq = 0;

    public function __construct(
        protected ?IncludeResolverInterface $resolver = null,
        protected ?string $currentPath = null,
        protected int $depthLimit = self::DEFAULT_DEPTH_LIMIT,
        protected ?int $byteBudget = null,
    ) {
    }

    public function transform(Document $document): Document
    {
        $this->warnings = [];
        $this->bytesUsed = 0;
        $this->scopeByObjectId = [];
        $this->scopeSeq = 0;

        $transformed = clone $document;
        if ($this->resolver === null) {
            return $transformed;
        }

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
            $directive = $this->parseDirective($this->textLikeContent($children));
            if ($directive !== null) {
                $replacement = $this->resolveDirective($directive, true, $currentPath, $stack, $depth, $budget);
                if ($replacement !== null) {
                    $parent->replaceChildWithMany($paragraph, $replacement);
                }

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

            $directive = $this->parseDirective($this->textLikeContent($run));
            if ($directive !== null) {
                $replacement = $this->resolveDirective($directive, false, $currentPath, $stack, $depth, $budget);
                if ($replacement !== null) {
                    for ($remove = count($run) - 1; $remove >= 1; $remove--) {
                        $node->removeChild($run[$remove]);
                    }
                    $node->replaceChildWithMany($run[0], $replacement);
                    $children = array_values($node->getChildren());
                    $count = count($children);
                    $i += count($replacement);

                    continue;
                }
            }

            $i = $j;
        }
    }

    /**
     * @param array{literal: string, path: string, section: string|null, lines: array{start: int, end: int}|null, shift: int} $directive
     * @param string|null $currentPath
     * @param bool $block
     * @param list<string> $stack
     * @param int $budget
     * @param int $depth
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
    ): ?array {
        if ($directive['section'] !== null && $directive['lines'] !== null) {
            $this->warn('Include directive cannot combine #section and @lines');

            return null;
        }

        if ($depth >= $this->depthLimit) {
            $this->warn("Include depth limit exceeded for '{$directive['path']}'");

            return null;
        }

        try {
            $resolved = $this->resolver?->resolve(
                $directive['path'],
                new IncludeContext($currentPath, $currentPath, $stack, $depth),
            );
        } catch (Throwable $exception) {
            $this->warn($exception->getMessage());

            return null;
        }

        if ($resolved === null) {
            $this->warn("Include could not be resolved: {$directive['path']}");

            return null;
        }

        $source = $resolved instanceof ResolvedInclude ? $resolved->getSource() : $resolved;
        $id = $resolved instanceof ResolvedInclude ? ($resolved->getId() ?? $directive['path']) : $directive['path'];
        // The cycle guard compares canonical ids after resolution, so a resolver
        // that supplies ids catches 'b.crv' vs './b.crv' spellings of one file.
        if (in_array($id, $stack, true)) {
            $this->warn("Include cycle detected for '{$directive['path']}'");

            return null;
        }

        if (str_contains($source, "\0") || preg_match('//u', $source) !== 1) {
            $this->warn("Include target is binary or non-text: {$directive['path']}");

            return null;
        }

        $bytes = strlen($source);
        if ($this->bytesUsed + $bytes > $budget) {
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
                $this->warn("Include has no section '#{$directive['section']}': {$directive['path']}");

                return null;
            }
        }

        $scope = $directive['path'] . '#' . (++$this->scopeSeq);
        $this->markScope($document, $scope);
        $this->expandChildren($document, $id, [...$stack, $id], $depth + 1, $budget);
        $this->shiftHeadings($document, $directive['shift']);

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
     * @return array{literal: string, path: string, section: string|null, lines: array{start: int, end: int}|null, shift: int}|null
     */
    protected function parseDirective(string $text): ?array
    {
        if (!preg_match('/^\{\{ (.+) \}\}$/s', $text, $match)) {
            return null;
        }

        $body = $match[1];
        if (str_starts_with($body, '"')) {
            if (!preg_match('/^"((?:\\\\.|[^"\\\\])*)"(.*)$/s', $body, $pathMatch)) {
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
            $this->warn("Duplicate footnote label '{$label}' renamed to '{$newLabel}'");
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
            $this->warn("Duplicate heading id '{$id}' renamed to '{$newId}'");
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

    protected function warn(string $message): void
    {
        $this->warnings[] = new ParseWarning($message, 1, 1, 'include');
    }
}
