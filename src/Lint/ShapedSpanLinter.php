<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\Strong;

/**
 * Hints at a parsed emphasis or strong span that reads like a C comment or a
 * file glob (markup-carve/carve#4815). It reads parsed nodes, so code and
 * escaped delimiters stay quiet.
 */
final class ShapedSpanLinter
{
    /**
     * @var string
     */
    public const RULE_COMMENT = 'comment-shaped-emphasis';

    /**
     * @var string
     */
    public const RULE_GLOB = 'glob-shaped-strong';

    /**
     * @param string $source
     *
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source): array
    {
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();
        $pending = [$converter->parse($source)];
        $map = SourceOffsets::map($source);
        $length = strlen($source);
        $warnings = [];
        while ($pending !== []) {
            $node = array_pop($pending);
            foreach ($node->getChildren() as $child) {
                $pending[] = $child;
            }
            $isEmphasis = $node instanceof Emphasis;
            if ((!$isEmphasis && !$node instanceof Strong) || $node->getPos() === null) {
                continue;
            }
            $pos = $node->getPos();
            $start = SourceOffsets::toByte($pos->startOffset, $map, $length);
            $raw = substr($source, $start, SourceOffsets::toByte($pos->endOffset, $map, $length) - $start);
            $delimiter = $isEmphasis ? '/' : '*';
            $inner = $isEmphasis ? '*' : '/';
            if (strlen($raw) < 3 || $raw[0] !== $delimiter || $raw[-1] !== $delimiter) {
                continue;
            }
            $commentShape = $raw[1] === $inner || $raw[-2] === $inner;
            if (!$commentShape && ($isEmphasis || $raw[1] !== '.')) {
                continue;
            }
            $warnings[] = new LintWarning(
                $pos->startLine,
                $pos->startColumn,
                $commentShape ? self::RULE_COMMENT : self::RULE_GLOB,
                $commentShape
                    ? 'This emphasis looks like a C comment; use Carve comment syntax if you meant a comment.'
                    : 'This strong span looks like a file glob; escape the asterisk if you meant a glob.',
                $start,
                $start + 1,
            );
        }

        return $warnings;
    }
}
