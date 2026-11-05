<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Utility\AttributeParser;

/**
 * `block-opener-overindented` and `emphasis-marker-before-attributes` (carve#3992), ported from carve-js#3975.
 */
final class FragileConstructLinter
{
    /**
     * @var array<string, string>
     */
    private const OPENER_OWNERS = [
        'doc' => 'the document',
        'quote' => 'its block quote',
        'note' => 'its footnote body',
        'dd' => 'its description',
        'colon' => 'its fenced container',
    ];

    /**
     * @var array<string, string>
     */
    private const SPAN_NAMES = [
        '*' => 'strong',
        '/' => 'emphasis',
        '_' => 'underline',
        '~' => 'strikethrough',
        '=' => 'highlight',
    ];

    /**
     * Codepoints of the source as given; AST offsets index them.
     *
     * @var array<int, string>
     */
    private array $cps = [];

    /**
     * Byte offset of each codepoint, plus one past the end.
     *
     * @var array<int, int>
     */
    private array $bytes = [];

    /**
     * @var array<int, int>
     */
    private array $lineStarts = [];

    /**
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source): array
    {
        [$converter, $document] = LintParse::parse($source);
        $this->index($source);
        $warnings = [];
        foreach ($converter->getParser()->getOverindentedOpeners() as $opener) {
            $warnings[] = $this->overindentedOpener($source, $opener);
        }
        $this->markersBeforeAttributes($document, $warnings);

        return $warnings;
    }

    /**
     * PART 0 S3: an opener past its owner's content column still opens there.
     *
     * @param string $source
     * @param array{line: int, column: int, kind: string, owner: string, contentColumn: int, authoredColumn: int} $opener
     *
     * @return \MarkupCarve\Carve\Lint\LintWarning
     */
    private function overindentedOpener(string $source, array $opener): LintWarning
    {
        $past = $opener['authoredColumn'] - $opener['contentColumn'];
        // The walk's columns do not count a BOM; the codepoint index does.
        $bom = $opener['line'] === 1 && ($this->cps[0] ?? '') === "\u{FEFF}" ? 1 : 0;
        $at = ($this->lineStarts[$opener['line'] - 1] ?? 0) + $opener['column'] - 1 + $bom;
        $start = $this->bytes[$at] ?? strlen($source);

        return new LintWarning(
            $opener['line'],
            $opener['column'],
            'block-opener-overindented',
            sprintf(
                'This %1$s is indented %2$d column%3$s past the content column of %4$s, and it still opens a %1$s, measured from its own line. Remove the indentation to make the structure explicit, or escape the opener to keep the line as text.',
                $opener['kind'],
                $past,
                $past === 1 ? '' : 's',
                self::OPENER_OWNERS[$opener['owner']] ?? 'its container',
            ),
            $start,
            $this->bytes[$at + 1] ?? $start,
        );
    }

    /**
     * A bare closer glued to an attribute block reads two ways: the block attaches
     * to the span the marker closes, or both stay text. Report the text reading,
     * and the attaching one when the span also holds a literal same marker.
     *
     * @param \MarkupCarve\Carve\Node\Node $document
     * @param list<\MarkupCarve\Carve\Lint\LintWarning> $warnings
     *
     * @return void
     */
    private function markersBeforeAttributes(Node $document, array &$warnings): void
    {
        $pending = [$document];
        while ($pending !== []) {
            $node = array_pop($pending);
            array_push($pending, ...array_reverse($node->getChildren()));
            $pos = $node->getPos();
            if ($pos === null || $node === $document) {
                continue;
            }
            if ($node instanceof Text) {
                for ($i = $pos->startOffset; $i < $pos->endOffset - 1; $i++) {
                    $ch = $this->cps[$i] ?? '';
                    if (!isset(self::SPAN_NAMES[$ch]) || $this->escaped($i)) {
                        continue;
                    }
                    $end = $this->blockEnd($i + 1);
                    if ($end === null || $end > $pos->endOffset) {
                        continue;
                    }
                    $warnings[] = $this->markerWarning($i, $end, sprintf(
                        'This %1$s closes no span, so it and the attribute block after it stay text. Brace the span you mean ({%1$stext%1$s}{…}) to attach the block, or escape the %1$s to keep both as text.',
                        $ch,
                    ));
                }

                continue;
            }
            $marker = $this->cps[$pos->startOffset] ?? '';
            if ($node->getAttributes() === [] || !isset(self::SPAN_NAMES[$marker]) || $this->escaped($pos->startOffset)) {
                continue;
            }
            $from = $pos->startOffset + 1;
            foreach ($node->getChildren() as $child) {
                $from = max($from, $child->getPos()->endOffset ?? $from);
            }
            $closer = -1;
            for ($j = $from; $j < $pos->endOffset; $j++) {
                if ($this->cps[$j] === $marker && !$this->escaped($j) && ($this->cps[$j + 1] ?? '') === '{') {
                    $closer = $j;

                    break;
                }
            }
            if ($closer < 0 || !$this->holdsLiteral($node, $marker, $closer)) {
                continue;
            }
            $line = $this->lineOf($pos->startOffset);
            $warnings[] = $this->markerWarning($closer, $this->blockEnd($closer + 1) ?? $closer + 1, sprintf(
                'This %1$s closes the %2$s span that opens at line %3$d, column %4$d, so the attribute block after it attaches to that whole span, which also holds a literal %1$s. Brace the span you mean ({%1$stext%1$s}{…}) or escape the inner %1$s to make the pairing explicit.',
                $marker,
                self::SPAN_NAMES[$marker],
                $line + 1,
                $pos->startOffset - $this->lineStarts[$line] + 1,
            ));
        }
    }

    /**
     * Only a marker the span holds as literal text counts, not one in code or a nested construct.
     */
    private function holdsLiteral(Node $span, string $marker, int $closer): bool
    {
        $pending = $span->getChildren();
        while ($pending !== []) {
            $node = array_pop($pending);
            array_push($pending, ...$node->getChildren());
            $pos = $node->getPos();
            if (!$node instanceof Text || $pos === null) {
                continue;
            }
            for ($j = $pos->startOffset; $j < min($pos->endOffset, $closer); $j++) {
                if ($this->cps[$j] === $marker && !$this->escaped($j)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * End index (exclusive) of a valid attribute block opening at `$i`.
     */
    private function blockEnd(int $i): ?int
    {
        if (($this->cps[$i] ?? '') !== '{') {
            return null;
        }
        $quote = null;
        $count = count($this->cps);
        for ($j = $i + 1; $j < $count; $j++) {
            $ch = $this->cps[$j];
            if ($ch === '\\') {
                $j++;

                continue;
            }
            if ($quote !== null) {
                if ($ch === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
            } elseif ($ch === '{') {
                return null;
            } elseif ($ch === '}') {
                return AttributeParser::isValidPayload(implode('', array_slice($this->cps, $i + 1, $j - $i - 1))) ? $j + 1 : null;
            }
        }

        return null;
    }

    private function escaped(int $i): bool
    {
        $n = 0;
        while ($i - 1 - $n >= 0 && $this->cps[$i - 1 - $n] === '\\') {
            $n++;
        }

        return $n % 2 === 1;
    }

    private function markerWarning(int $at, int $end, string $message): LintWarning
    {
        $line = $this->lineOf($at);

        return new LintWarning(
            $line + 1,
            $at - $this->lineStarts[$line] + 1 - ($line === 0 && ($this->cps[0] ?? '') === "\u{FEFF}" ? 1 : 0),
            'emphasis-marker-before-attributes',
            $message,
            $this->bytes[$at],
            $this->bytes[$end] ?? $this->bytes[$at],
        );
    }

    private function lineOf(int $offset): int
    {
        $lo = 0;
        $hi = count($this->lineStarts) - 1;
        while ($lo < $hi) {
            $mid = ($lo + $hi + 1) >> 1;
            if ($this->lineStarts[$mid] <= $offset) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }

        return $lo;
    }

    private function index(string $source): void
    {
        $this->cps = mb_str_split($source, 1, 'UTF-8');
        $this->bytes = [];
        $this->lineStarts = [0];
        $byte = 0;
        foreach ($this->cps as $i => $ch) {
            $this->bytes[$i] = $byte;
            $byte += strlen($ch);
            if ($ch === "\n" || ($ch === "\r" && ($this->cps[$i + 1] ?? '') !== "\n")) {
                $this->lineStarts[] = $i + 1;
            }
        }
        $this->bytes[count($this->cps)] = $byte;
    }
}
