<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Carve has no shortcut reference, so a CommonMark `[r]` with a definition is
 * written as a collapsed or full reference (#2086).
 */
class AMarkdownShortcutReferenceIsALinkTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'a defined label' => ["[r]\n\n[r]: /u", "[r][]\n\n[r]: /u"],
            'twice in a line' => ["x [r] y [r] z\n\n[r]: /u", "x [r][] y [r][] z\n\n[r]: /u"],
            'an image' => ["![r]\n\n[r]: /u", "![r][]\n\n[r]: /u"],
            'a label matched by case and whitespace' => ["[R x]\n\n[r  X]: /u", "[R x][r  X]\n\n[r  X]: /u"],
            'a label holding emphasis' => ["[a *b*]\n\n[a *b*]: /u", "[a /b/][a *b*]\n\n[a *b*]: /u"],
            'a label holding an escape' => ["[a\\*b]\n\n[a\\*b]: /u", "[a\\*b][a\\*b]\n\n[a\\*b]: /u"],
            'a colon after it mid-line' => ["x [r]: y\n\n[r]: /u", "x [r][]: y\n\n[r]: /u"],
            'an unclosed parenthesis after it' => ["[r](x\n\n[r]: /u", "[r][](x\n\n[r]: /u"],
            'inside brackets' => ["[[r]]\n\n[r]: /u", "[[r][]]\n\n[r]: /u"],
            'the first definition names the label' => ["[R]\n\n[r]: /u\n[R]: /v", "[R][r]\n\n[r]: /u\n[R]: /v"],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheShortcutIsWrittenAsAReference(string $markdown, string $carve): void
    {
        $this->assertSame($carve, rtrim((new MarkdownToCarve())->convert($markdown), "\n"));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unchanged(): array
    {
        return [
            'an undefined label' => ["[z]\n\n[r]: /u"],
            'an inline link' => ["[r](/v)\n\n[r]: /u"],
            'a collapsed reference' => ["[r][]\n\n[r]: /u"],
            'a full reference to an undefined label' => ["[r][s]\n\n[r]: /u"],
            'a code span' => ["`[r]`\n\n[r]: /u"],
            'an escaped bracket' => ["\\[r]\n\n[r]: /u"],
            'a footnote label' => ["[^r]\n\n[^r]: /u"],
            'a definition whose destination is on the next line' => ["[r]:\n/u"],
        ];
    }

    #[DataProvider('unchanged')]
    public function testAnythingElseIsLeftAlone(string $markdown): void
    {
        $this->assertSame($markdown, rtrim((new MarkdownToCarve())->convert($markdown), "\n"));
    }
}
