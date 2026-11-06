<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\SourceLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChildOwnedColonCloserTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function containers(): array
    {
        return [
            'open code' => ["::: note\n```\nx\n:::\n", true],
            'closed code' => ["::: note\n```\nx\n```\n:::\n", false],
            'open comment' => ["::: |\na\n%%%\nc\n:::\n", true],
            'closed comment' => ["::: |\na\n%%%\nc\n%%%\n:::\n", false],
        ];
    }

    #[DataProvider('containers')]
    public function testChildPayloadCannotCloseItsOuterContainer(string $source, bool $unclosed): void
    {
        $findings = array_values(array_filter(
            (new SourceLinter())->lint($source),
            static fn ($finding): bool => $finding->rule === 'unclosed-container-fence',
        ));
        self::assertCount($unclosed ? 1 : 0, $findings);
        if ($unclosed) {
            self::assertSame(1, $findings[0]->line);
            self::assertSame(1, $findings[0]->column);
        }
    }
}
