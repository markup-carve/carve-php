<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6944: the write or refuse decision of carve-js and carve-rs for a colon line behind spaces in a line block
 * (C2-W-090) and for a line comment that ends the content of an inline extension (C2-I-024).
 */
final class WriterRefusalParity6944Test extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function colonLines(): array
    {
        return [
            'two spaces' => ["::: |\n  \\:::\n:::\n"],
            'three spaces after a line' => ["::: |\n  x\n   \\:::\n:::\n"],
            'four colons' => ["::: |\n      \\::::\n:::\n"],
            'in a quote' => ["> ::: |\n>   \\:::\n> :::\n"],
            'in a list item' => ["- ::: |\n    \\:::\n  :::\n"],
        ];
    }

    #[DataProvider('colonLines')]
    public function testAColonLineBehindSpacesFormatsUnchanged(string $source): void
    {
        $result = Writer::formatWithDiagnostics($source);
        $this->assertSame($source, $result->source);
        $this->assertSame([], $result->codes());
    }

    public function testTheMigratedTreeOfRow914Writes(): void
    {
        $source = "- :: u\n  : ::: |\n     [x]: /u\n    - l\n       \\:::\n    b\n    :::\n";
        $result = Writer::writeWithDiagnostics(self::tree($source));
        $this->assertSame($source, $result->source);
        $this->assertSame([], $result->codes());
    }

    /**
     * @return array<string, array{array<mixed>, string}>
     */
    public static function trailingComments(): array
    {
        $comment = ['type' => 'comment', 'form' => 'line', 'value' => 'c'];

        return [
            'only the comment' => [[$comment], '/children/0/children/0/children/0'],
            'text and the comment' => [[['type' => 'text', 'value' => 'a'], $comment], '/children/0/children/0/children/1'],
        ];
    }

    /**
     * @param array<mixed> $content
     * @param string $path
     */
    #[DataProvider('trailingComments')]
    public function testALineCommentBeforeTheCloserIsReported(array $content, string $path): void
    {
        $tree = [
            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'inline_extension', 'name' => 'foo', 'children' => $content],
                        ['type' => 'text', 'value' => ' d'],
                    ],
                ],
            ],
        ];
        $result = Writer::writeWithDiagnostics($tree);
        $this->assertContains('structure-unspellable', $result->codes());
        $this->assertContains($path, array_column($result->diagnostics, 'path'));
    }

    public function testALineCommentBeforeALineBreakStillWrites(): void
    {
        $source = ":foo[a %% c\n] d\n";
        $result = Writer::formatWithDiagnostics($source);
        $this->assertSame($source, $result->source);
        $this->assertSame([], $result->codes());
    }

    /**
     * @return array<mixed>
     */
    private static function tree(string $source): array
    {
        $tree = json_decode(Carve::toJson($source), true);
        self::assertIsArray($tree);

        return $tree;
    }
}
