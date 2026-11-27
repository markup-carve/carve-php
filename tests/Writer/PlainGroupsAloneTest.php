<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#6119, C2-W-088: a group's form does not depend on the other groups. The first paragraph is written as
 * authored, as it is alone and as carve-js `next2` writes the whole document.
 */
final class PlainGroupsAloneTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function provideGroups(): array
    {
        $source = "\\[^[\\`[[[^]\\[]][]\\[\n\n\\[^[\\`[`[[^]\\[]][]\\[\n\na[\\][]`a][\\`][[][\\``\\`\n";

        return [
            'three groups' => [$source, false],
            'three groups, normalize' => [$source, true],
            'first group alone' => ["\\[^[\\`[[[^]\\[]][]\\[\n", false],
        ];
    }

    #[DataProvider('provideGroups')]
    public function testEachGroupKeepsItsAloneForm(string $source, bool $normalize): void
    {
        $options = $normalize ? ['normalize' => true] : [];

        $result = Carve::formatWithDiagnostics($source, $options);

        $this->assertSame([], $result->diagnostics);
        $this->assertSame($source, $result->source);
    }
}
