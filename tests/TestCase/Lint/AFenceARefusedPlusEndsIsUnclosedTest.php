<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\DocumentLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `+` at the host's marker column ends an open fence; with no closer the fence is unclosed (carve#5951).
 */
class AFenceARefusedPlusEndsIsUnclosedTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function provider(): array
    {
        return [
            'blank follows' => ["- ```\n  x\n+\n\ntail\n", ['1:3 unclosed-code-fence', '3:1 refused-continuation-marker']],
            'raw fence' => ["- ```=html\n  x\n+\n\ntail\n", ['1:3 unclosed-code-fence', '3:1 refused-continuation-marker']],
            'block right of the column follows' => ["- ```\n  x\n+\n  p\n", ['1:3 unclosed-code-fence', '3:1 refused-continuation-marker']],
            'empty payload' => ["- ```\n+\n\ntail\n", ['1:3 unclosed-code-fence', '2:1 refused-continuation-marker']],
            'note' => ["[^a]: +\n- li\n  ```\n+\n  p\n\nsee[^a]\n", ['3:3 unclosed-code-fence', '4:1 refused-continuation-marker']],
        ];
    }

    /**
     * @param string $source
     * @param list<string> $expected
     */
    #[DataProvider('provider')]
    public function testTheFenceIsReported(string $source, array $expected): void
    {
        $found = array_map(
            static fn ($warning): string => $warning->line . ':' . $warning->column . ' ' . $warning->rule,
            (new DocumentLinter())->lint($source),
        );

        $this->assertSame($expected, $found);
    }

    public function testAClosedFenceBeforeAPlusIsNotReported(): void
    {
        $rules = array_column((new DocumentLinter())->lint("- ```\n  x\n  ```\n+\n\ntail\n"), 'rule');

        $this->assertNotContains('unclosed-code-fence', $rules);
    }
}
