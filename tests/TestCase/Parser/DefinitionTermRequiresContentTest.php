<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9's MARKER REQUIRES CONTENT covers every marker that takes a separator
 * space, including the definition-term marker `::`.
 *
 * `::` and `:: ` were already paragraphs here. `::` plus a SECOND space was
 * not: `\s+(.+)` let the greedy `\s+` take one space and `(.+)` the other, so
 * the line opened a definition list with an EMPTY term. Deleting one invisible
 * character therefore changed the document's structure - the exact thing the
 * rule's rationale exists to prevent, since editors strip trailing whitespace
 * on save and `git apply --whitespace=fix` strips it too.
 *
 * Three engines carried three answers for that one line: this one an empty
 * term, carve-js a term holding the space, carve-rs a paragraph. carve-rs was
 * right (carve#512).
 */
final class DefinitionTermRequiresContentTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function contentLessProvider(): array
    {
        return [
            'bare' => ["::\n"],
            'one trailing space' => [":: \n"],
            'two trailing spaces' => ["::  \n"],
            'many trailing spaces' => ["::     \n"],
            'a trailing tab' => ["::\t\n"],
            'a space then a tab' => [":: \t\n"],
        ];
    }

    #[DataProvider('contentLessProvider')]
    public function testAContentLessTermMarkerIsParagraphText(string $source): void
    {
        $html = $this->converter->convert($source);

        $this->assertStringNotContainsString('<dl>', $html);
        $this->assertStringContainsString('<p>::</p>', $html);
    }

    public function testATermWithContentStillOpensADefinitionList(): void
    {
        $this->assertStringContainsString('<dt>t</dt>', $this->converter->convert(":: t\n"));
    }

    public function testATabSeparatorStillWorks(): void
    {
        $this->assertStringContainsString('<dt>t</dt>', $this->converter->convert("::\tt\n"));
    }

    public function testAFullTermAndDefinitionStillWork(): void
    {
        $html = $this->converter->convert(":: a\n:  b\n");

        $this->assertStringContainsString('<dt>a</dt>', $html);
        $this->assertStringContainsString('<dd>b</dd>', $html);
    }
}
