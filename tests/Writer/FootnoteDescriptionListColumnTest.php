<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-B-067, C2-B-087: a description list opened on a footnote marker line takes its descriptions at the term
 * marker column and its term continuations at the term text column (carve#6506).
 */
final class FootnoteDescriptionListColumnTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function sources(): array
    {
        return [
            'term continuation and description' => [
                "Text.[^1]\n\n[^1]: :: A long term\n         continued term line\n      : The description.\n",
            ],
            'term continuation only' => ["Text.[^1]\n\n[^1]: :: A long term\n         continued term line\n"],
            'one-line term and description' => ["Text.[^1]\n\n[^1]: :: A term\n      : The description.\n"],
            'five-character label, three lines, two descriptions' => [
                "Text.[^abcde]\n\n[^abcde]: :: Term\n             two\n             three\n          : One.\n          : Two.\n",
            ],
            'non-ASCII label' => ["Text.[^日本]\n\n[^日本]: :: Term\n          cont\n       : Desc.\n"],
            'later block of the note' => ["Text.[^1]\n\n[^1]: :: Term\n         cont\n      : Desc.\n\n      Para.\n"],
            'second entry' => ["Text.[^1]\n\n[^1]: :: T\n      : D\n      :: T2\n         cont\n      : D2\n"],
            'term as a later block' => ["Text.[^1]\n\n[^1]: Para.\n\n  :: A long term\n     continued\n  : The description.\n"],
            'list item' => ["- :: A long term\n     continued\n  : The description.\n"],
            'quote' => ["> :: A long term\n>    continued\n> : The description.\n"],
        ];
    }

    /**
     * @param string $source
     *
     * @return void
     */
    #[DataProvider('sources')]
    public function testTheEntryKeepsItsColumns(string $source): void
    {
        $this->assertSame($source, Carve::format($source));
    }
}
