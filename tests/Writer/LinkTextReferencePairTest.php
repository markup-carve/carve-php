<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5632: a bracket pair balanced inside bracketed content stays bare only when it forms nothing (C2-W-094).
 */
final class LinkTextReferencePairTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'label resolves' => ["[x]: /u\n\n[a \\[x\\] b](u)\n", "[x]: /u\n\n[a \\[x\\] b](u)\n"],
            'a label of another case resolves nothing' => ["[X]: /u\n\n[a \\[x\\] b](u)\n", "[X]: /u\n\n[a [x] b](u)\n"],
            'link tail follows' => ["[a \\[x\\](v) b](u)\n", "[a \\[x\\](v) b](u)\n"],
            'brace block follows' => ["[a \\[x\\]{.c} b](u)\n", "[a \\[x\\]{.c} b](u)\n"],
            'caret label' => ["[^x]: note\n\n[a \\[^x\\] b](u)\n", "[^x]: note\n\n[a \\[^x\\] b](u)\n"],
            'in a span' => ["[x]: /u\n\n[a \\[x\\] b]{.c}\n", "[x]: /u\n\n[a \\[x\\] b]{.c}\n"],
            'no label: the pair stays bare' => ["[a \\[x\\] b](u)\n", "[a [x] b](u)\n"],
            'no footnote forms in an inline footnote' => ["a ^[b [^1] c]\n\n[^1]: note\n", "a ^[b [^1] c]\n\n[^1]: note\n"],
        ];
    }

    #[DataProvider('cases')]
    public function testAPairThatFormsAConstructKeepsItsBackslashes(string $input, string $expected): void
    {
        $source = Carve::format($input);

        self::assertSame($expected, $source);
        self::assertTrue(TreeEquality::equal(
            TreeEncoder::decodedForWriter(Carve::parse($input)),
            TreeEncoder::decodedForWriter(Carve::parse($source)),
        ));
    }
}
