<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PairAndAttributedOpenerTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, array<string, mixed>, bool}>
     */
    public static function cases(): array
    {
        $cases = [];
        foreach (
            [
                '**' => 'markdown-strong-double-star',
                '~~' => 'markdown-strikethrough-double-tilde',
                '_' => 'djot-emphasis-underscore',
                '~' => 'djot-subscript-tilde',
                '^' => 'djot-superscript-caret',
            ] as $mark => $rule
        ) {
            $options = str_starts_with($rule, 'djot-') ? ['rules' => [$rule => 'on']] : [];
            foreach ([1 => false, 2 => true, 3 => false, 4 => true] as $slashes => $reported) {
                $cases[$rule . ' slashes ' . $slashes] = [
                    'a ' . str_repeat('\\', $slashes) . $mark . 'b' . $mark . " c\n", $rule, 'pair', $options, $reported,
                ];
            }
        }
        $cases['one intervening comment'] = ["{.a}\n  %% x\n# h\n", 'block-opener-overindented', 'opener', [], false];
        $cases['several intervening comments'] = [
            "{.a}\n    %% one\n%% two\n  %% three\n# h\n", 'block-opener-overindented', 'opener', [], false,
        ];
        $cases['indented structural opener'] = ["{.a}\n%% x\n  # h\n", 'block-opener-overindented', 'opener', [], true];

        return $cases;
    }

    /**
     * @param string $source
     * @param string $rule
     * @param string $family
     * @param array<string, mixed> $options
     * @param bool $reported
     */
    #[DataProvider('cases')]
    public function testBothLintModes(string $source, string $rule, string $family, array $options, bool $reported): void
    {
        foreach (['data', 'hand'] as $mode) {
            $previous = getenv('CARVE_LINT_RULES');
            putenv($mode === 'data' ? 'CARVE_LINT_RULES=data' : 'CARVE_LINT_RULES');
            try {
                $findings = array_values(array_filter(
                    Carve::lint($source, $options),
                    static fn ($finding): bool => $finding->code === $rule,
                ));
                self::assertSame($reported, $findings !== [], $mode . ': ' . $family . ': ' . $source);
            } finally {
                putenv($previous === false ? 'CARVE_LINT_RULES' : 'CARVE_LINT_RULES=' . $previous);
            }
        }
    }
}
