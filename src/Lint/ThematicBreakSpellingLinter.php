<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\ThematicBreak;
use MarkupCarve\Carve\Node\Node;

/**
 * Hints every thematic break not written `***`, the spelling `carve fmt` writes (carve#4973).
 */
final class ThematicBreakSpellingLinter
{
    /**
     * @var string
     */
    public const RULE = 'thematic-break-spelling';

    /**
     * @var string
     */
    private const CANONICAL = '***';

    /**
     * @var list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    private array $warnings = [];

    private string $source = '';

    /**
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source): array
    {
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();
        $this->source = $source;
        $this->warnings = [];
        $this->visit($converter->parse($source));

        return $this->warnings;
    }

    private function visit(Node $node): void
    {
        $pos = $node->getPos();
        if ($node instanceof ThematicBreak && $pos !== null) {
            $start = $pos->startOffset;
            $raw = rtrim(substr($this->source, $start, $pos->endOffset - $start));
            if ($raw !== self::CANONICAL) {
                $lineStart = strrpos(substr($this->source, 0, $start), "\n");
                $lineStart = $lineStart === false ? 0 : $lineStart + 1;
                $this->warnings[] = new LintWarning(
                    $pos->startLine,
                    mb_strlen(substr($this->source, $lineStart, $start - $lineStart), 'UTF-8') + 1,
                    self::RULE,
                    sprintf('This thematic break is written "%s"; the canonical spelling is "***".', $raw),
                    $start,
                    $start + strlen($raw),
                );
            }
        }
        foreach ($node->getChildren() as $child) {
            $this->visit($child);
        }
    }
}
