<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-150: id records give the same findings in the hand-written and the data rules.
 */
final class IdRecordModesTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function cases(): array
    {
        $panel = "::: figure\n{#p}\n![a](a)\n^ (a) One\n:::\n^ Figure #: Both\n\nSee </#P>.\n";

        return [
            'a panel of a numbered group is a target' => [$panel, 'broken-crossref', 'the id p differs only in case'],
            'an autolink heading gives its text' => [
                "# <https://example.com>\n\n[x][HTTPS://EXAMPLE.COM]\n",
                'unresolved-reference-link',
                'the label https://example.com differs only in case',
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testBothModesGiveTheHint(string $source, string $code, string $phrase): void
    {
        foreach ([false, true] as $data) {
            $messages = self::messages($source, $code, $data);
            self::assertCount(1, $messages);
            self::assertStringContainsString($phrase, $messages[0]);
        }
    }

    public function testAKeySharedWithAHeadingStaysATarget(): void
    {
        $source = "a\n\n{#same}\n---yaml\nk: v\n---\n\n{#same}\n# H\n\n[x](#same)\n";
        foreach ([false, true] as $data) {
            self::assertSame([], self::messages($source, 'broken-fragment-link', $data));
        }
    }

    /**
     * @return array<string>
     */
    private static function messages(string $source, string $code, bool $data): array
    {
        $previous = getenv('CARVE_LINT_RULES');
        putenv($data ? 'CARVE_LINT_RULES=data' : 'CARVE_LINT_RULES');
        try {
            $found = Carve::lint($source);
        } finally {
            putenv($previous === false ? 'CARVE_LINT_RULES' : 'CARVE_LINT_RULES=' . $previous);
        }

        return array_values(array_map(
            static fn ($d): string => $d->message,
            array_filter($found, static fn ($d): bool => $d->code === $code),
        ));
    }
}
