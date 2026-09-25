<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\CitationsExtension;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Node;

class ReferencesPlacementLinter
{
    /**
     * @var string
     */
    public const RULE = 'references-placement-in-container';

    /**
     * @param string $source
     * @param array{extensions?: list<string|\MarkupCarve\Carve\Extension\ExtensionInterface>} $options
     *
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source, array $options = []): array
    {
        $citationsEnabled = false;
        foreach ($options['extensions'] ?? [] as $extension) {
            if ($extension === 'citations' || $extension instanceof CitationsExtension) {
                $citationsEnabled = true;

                break;
            }
        }
        if (!$citationsEnabled) {
            return [];
        }

        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();
        $document = $converter->parse($source);
        $byteAt = SourceOffsets::map($source);
        $warnings = [];
        $this->collect($document, false, $byteAt, strlen($source), $warnings);

        return $warnings;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param bool $contained
     * @param array<int, int>|null $byteAt
     * @param int $sourceLength
     * @param list<\MarkupCarve\Carve\Lint\LintWarning> $warnings
     */
    private function collect(Node $parent, bool $contained, ?array $byteAt, int $sourceLength, array &$warnings): void
    {
        foreach ($parent->getChildren() as $child) {
            if (
                $child instanceof Div && $contained && $child->isTyped()
                && ($child->getClassList()[0] ?? null) === 'references'
            ) {
                $pos = $child->getPos();
                $line = 1;
                $column = 1;
                $start = 0;
                $end = 0;
                if ($pos !== null) {
                    $line = $pos->startLine;
                    $column = $pos->startColumn;
                    $start = SourceOffsets::toByte($pos->startOffset, $byteAt, $sourceLength);
                    $end = SourceOffsets::toByte($pos->endOffset, $byteAt, $sourceLength);
                }
                $warnings[] = new LintWarning(
                    $line,
                    $column,
                    self::RULE,
                    'This "::: references" marker is inside a container and does not place the reference list. Move it to document level, or remove it if no placement is needed.',
                    $start,
                    $end,
                );
            }
            $this->collect($child, true, $byteAt, $sourceLength, $warnings);
        }
    }
}
