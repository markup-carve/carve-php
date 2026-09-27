<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\HeadingRef;
use MarkupCarve\Carve\Node\Inline\InlineNode;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\LabelKey;
use MarkupCarve\Carve\Renderer\CrossReferenceResolver;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;

class ReferenceLinter
{
    /**
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source): array
    {
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();
        $document = $converter->parse($source);
        $tracker = new HeadingIdTracker();
        (new CrossReferenceResolver())->resolveCrossReferenceTargets($document, $tracker);
        $map = SourceOffsets::map($source);
        $length = strlen($source);
        $warnings = [];
        $nodes = [];
        $pending = [$document];
        while ($pending !== []) {
            $node = array_pop($pending);
            $nodes[] = $node;
            foreach (array_reverse($node->getChildren()) as $child) {
                $pending[] = $child;
            }
        }
        $definitions = [];
        $referenced = [];
        $usedIds = [];
        $ignoredLines = [];
        $inlineSpans = [];
        foreach ($nodes as $node) {
            $pos = $node->getPos();
            if ($pos !== null && ($node instanceof CodeBlock || $node instanceof RawBlock || $node instanceof Comment)) {
                for ($line = $pos->startLine; $line <= $pos->endLine; $line++) {
                    $ignoredLines[$line] = true;
                }
            }
            if ($pos !== null && $node instanceof InlineNode) {
                $inlineSpans[] = [SourceOffsets::toByte($pos->startOffset, $map, $length), SourceOffsets::toByte($pos->endOffset, $map, $length)];
            }
            if ($node instanceof Footnote) {
                $definitions[LabelKey::normalize($node->getLabel())] = $node;
            }
            if (!$node instanceof Heading) {
                continue;
            }
            $explicit = $node->getAttribute('id');
            $base = $explicit ?? $tracker->normalizeId($tracker->getPlainText($node));
            $collision = $explicit !== null
                ? isset($usedIds[$explicit])
                : $tracker->getIdForHeading($node) !== $base;
            if ($collision) {
                $warnings[] = $this->warning($node, 'duplicate-heading-id', 'Heading id "' . $base . '" collides with another heading or an explicit id.', $map, $length);
            }
            if ($explicit !== null) {
                $usedIds[$explicit] = true;
            }
        }
        foreach ($nodes as $node) {
            if ($node instanceof HeadingRef && $node->getHref() === null) {
                $warnings[] = $this->warning($node, 'broken-crossref', 'Cross-reference </#' . $node->getTargetId() . '> has no matching heading id.', $map, $length);
            } elseif ($node instanceof Link && $node->getReferenceLabel() !== null && ($node->getDestination() ?? '') === '') {
                $warnings[] = $this->warning($node, 'unresolved-reference-link', 'Reference has no matching definition or heading.', $map, $length);
            } elseif ($node instanceof FootnoteRef) {
                $key = LabelKey::normalize($node->getLabel());
                if (isset($definitions[$key])) {
                    $referenced[$key] = true;
                } else {
                    $warnings[] = $this->warning($node, 'unresolved-footnote', 'Footnote reference [^' . $node->getLabel() . '] has no matching definition.', $map, $length);
                }
            }
        }
        sort($inlineSpans);
        $spanIndex = 0;
        $seen = [];
        $seenKeys = [];
        $sites = [];
        $lines = preg_split('/\r\n|\r|\n/', $source, flags: PREG_SPLIT_OFFSET_CAPTURE) ?: [];
        foreach ($lines as $index => [$line, $offset]) {
            if (isset($ignoredLines[$index + 1]) || !preg_match('/^(?:[ \t]*> ?)*[ \t]*(?:[-+*] |[0-9]+[.)] |: )*\[\^([^\]\r\n]+)\]:/', $line, $match)) {
                continue;
            }
            $label = $match[1];
            $key = LabelKey::normalize($label);
            if (!isset($definitions[$key])) {
                continue;
            }
            $marker = strpos($line, '[^');
            if ($marker === false) {
                continue;
            }
            $start = $offset + $marker;
            while (isset($inlineSpans[$spanIndex]) && $inlineSpans[$spanIndex][1] <= $start) {
                $spanIndex++;
            }
            if (isset($inlineSpans[$spanIndex]) && $inlineSpans[$spanIndex][0] <= $start && $start < $inlineSpans[$spanIndex][1]) {
                continue;
            }
            $rule = isset($seen[$label]) ? 'duplicate-footnote-definition' : (isset($seenKeys[$key]) ? 'footnote-labels-differ-only-in-whitespace' : null);
            if ($rule !== null) {
                $message = $rule === 'footnote-labels-differ-only-in-whitespace'
                    ? 'Footnote labels [^' . $label . '] and [^' . $seenKeys[$key] . '] differ only in whitespace; the first definition wins.'
                    : 'Footnote definition [^' . $label . '] repeats an earlier definition; the first definition wins.';
                $warnings[] = new LintWarning($index + 1, mb_strlen(substr($line, 0, $marker), 'UTF-8') + 1, $rule, $message, $start, $offset + strlen($match[0]));
            }
            $seen[$label] = true;
            $seenKeys[$key] ??= $label;
            $sites[$key] ??= [$index + 1, mb_strlen(substr($line, 0, $marker), 'UTF-8') + 1, $start, $offset + strlen($match[0])];
        }
        foreach ($definitions as $key => $node) {
            if (!isset($referenced[$key])) {
                $message = 'Footnote definition [^' . $node->getLabel() . '] is never referenced and is omitted from rendered output.';
                if (isset($sites[$key])) {
                    [$line, $column, $start, $end] = $sites[$key];
                    $warnings[] = new LintWarning($line, $column, 'unused-footnote-definition', $message, $start, $end);
                } else {
                    $warnings[] = $this->warning($node, 'unused-footnote-definition', $message, $map, $length);
                }
            }
        }
        usort($warnings, static fn (LintWarning $a, LintWarning $b): int => $a->start <=> $b->start);

        return $warnings;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param string $rule
     * @param string $message
     * @param array<int, int>|null $map
     * @param int $length
     */
    private function warning(Node $node, string $rule, string $message, ?array $map, int $length): LintWarning
    {
        $pos = $node->getPos();

        if ($pos === null) {
            return new LintWarning(1, 1, $rule, $message, 0, 0);
        }

        return new LintWarning(
            $pos->startLine,
            $pos->startColumn,
            $rule,
            $message,
            SourceOffsets::toByte($pos->startOffset, $map, $length),
            SourceOffsets::toByte($pos->endOffset, $map, $length),
        );
    }
}
