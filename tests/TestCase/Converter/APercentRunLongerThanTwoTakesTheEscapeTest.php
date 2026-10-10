<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A comment opens on the first two UNESCAPED percent signs, and a third sign
 * does not stop it, so a run of any length needs one escape on the first sign
 * and the importer owes it (carve-php#3049).
 *
 * The derivation is the reader's, not another engine's: the bare run renders
 * `<p>a</p>` here, which is the loss the escape prevents.
 */
class APercentRunLongerThanTwoTakesTheEscapeTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function runs(): array
    {
        return [
            // The reported shape: three signs, bare before this rule.
            'three signs mid line' => ['a %%%c b', 'a \%%%c b'],
            'four signs mid line' => ['a %%%% b', 'a \%%%% b'],
            'five signs mid line' => ['a %%%%%c b', 'a \%%%%%c b'],
            'a run ending the line' => ['a %%%', 'a \%%%'],
            // The pair already took the escape and still takes exactly one.
            'two signs mid line' => ['a %% b', 'a \%% b'],
            // Controls: no opener, so no escape is owed.
            'a run inside a word' => ['a x%%y b', 'a x%%y b'],
            'a single sign' => ['a 50% b', 'a 50% b'],
        ];
    }

    #[DataProvider('runs')]
    public function testTheImporterWritesOneEscapeForTheRun(string $markdown, string $carve): void
    {
        $this->assertSame($carve . "\n", (new MarkdownToCarve())->convert($markdown . "\n"));
    }

    #[DataProvider('runs')]
    public function testTheWrittenSpellingReadsBackAsTheSourceText(string $markdown, string $carve): void
    {
        $this->assertSame('<p>' . $markdown . "</p>\n", (new CarveConverter())->convert($carve . "\n"));
    }

    /**
     * The loss the escape prevents, stated so the cases above have a reason.
     */
    public function testTheBareRunSwallowsTheRestOfTheLine(): void
    {
        $this->assertSame("<p>a</p>\n", (new CarveConverter())->convert("a %%%c b\n"));
    }
}
