<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-B-067, C2-B-229: a description list opened on a marker line behind a task box or item attributes takes its
 * descriptions at the term marker column and its term continuations at the term text column (carve#6512).
 */
final class MarkerLineDescriptionListColumnTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function sources(): array
    {
        return [
            'task item' => ["- [ ] :: A long term\n         continued\n      : The description.\n"],
            'checked task item, one-line term' => ["- [x] :: Term\n      : Desc.\n"],
            'ordered task item' => ["1. [ ] :: Term\n          cont\n       : Desc.\n"],
            'item attributes' => ["-{.c} :: Term\n         cont\n      : Desc.\n"],
            'attributes and task box' => ["-{.c} [ ] :: Term\n             cont\n          : Desc.\n"],
            'later block of the item' => ["- [ ] :: Term\n      : Desc.\n\n      Para.\n"],
            'second entry' => ["- [ ] :: T\n      : D\n      :: T2\n         cont\n      : D2\n"],
            'in a quote' => ["> - [ ] :: Term\n>          cont\n>       : Desc.\n"],
            'nested item' => ["- outer\n  - [ ] :: Term\n           cont\n        : Desc.\n"],
            'in a footnote' => ["Text.[^1]\n\n[^1]: - [ ] :: Term\n               cont\n            : Desc.\n"],
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
