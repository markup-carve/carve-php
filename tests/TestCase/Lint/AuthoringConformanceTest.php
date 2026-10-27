<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\DocumentLinter;
use PHPUnit\Framework\TestCase;

final class AuthoringConformanceTest extends TestCase
{
    /**
     * @var array
     */
    private const RULES = [
        'table-shaped-paragraph',
        'table-row-cell-count',
        'table-separator-near-miss',
        'list-marker-without-blank-line',
        'unclosed-inline-emphasis',
        'link-gap-before-destination',
        'markdown-italic-underline',
        'heading-level-skip',
        'bare-html-tag',
        'markdown-definition-paragraph',
        'github-alert-marker',
        'caption-looking-paragraph',
    ];

    public function testSharedRuleVectors(): void
    {
        foreach (self::RULES as $rule) {
            $path = __DIR__ . '/../../../tests/spec/tests/lint-rule-vectors/' . $rule . '.json';
            $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($rule, $data['rule']);
            foreach ($data['vectors'] as $vector) {
                $warnings = array_values(array_filter(
                    (new DocumentLinter())->lint($vector['source'], ['captionLookingParagraph' => $rule === 'caption-looking-paragraph']),
                    static fn ($warning): bool => $warning->rule === $rule,
                ));
                $expected = $vector['expected']['findings'];
                self::assertSame(
                    array_map(static fn (array $finding): array => [$finding['line'], $finding['column']], $expected),
                    array_map(static fn ($warning): array => [$warning->line, $warning->column], $warnings),
                    $rule . ': ' . $vector['name'],
                );
                foreach ($expected as $index => $finding) {
                    foreach ($finding['includes'] as $fragment) {
                        self::assertStringContainsString($fragment, $warnings[$index]->message, $rule . ': ' . $vector['name']);
                    }
                }
            }
        }
    }

    public function testAuthoringAcceptanceSuite(): void
    {
        $root = __DIR__ . '/../../../tests/spec/tests/lint-authoring/';
        $data = json_decode((string)file_get_contents($root . 'expectations.json'), true, 512, JSON_THROW_ON_ERROR);
        $linter = new DocumentLinter();
        $scorecard = 0;
        foreach ($data['cases'] as $case) {
            $source = (string)file_get_contents($root . $case['file']);
            $actual = array_map(static fn ($warning): array => [$warning->rule, $warning->line], $linter->lint($source));
            $expected = $case['expected'];
            sort($actual);
            sort($expected);
            self::assertSame($expected, $actual, $case['file']);
            if (str_starts_with($case['file'], 'errors/E')) {
                ++$scorecard;
            }
            if ($case['optIn'] !== []) {
                $optIn = array_values(array_map(
                    static fn ($warning): array => [$warning->rule, $warning->line],
                    array_filter($linter->lint($source, ['captionLookingParagraph' => true]), static fn ($warning): bool => $warning->rule === 'caption-looking-paragraph'),
                ));
                self::assertSame($case['optIn'], $optIn, $case['file']);
            }
        }
        self::assertSame(14, $scorecard);
    }
}
