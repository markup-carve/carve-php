<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AReachedOuterDescriptionOwnsTheNextBlockTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function reachedDescriptions(): array
    {
        return [
            'full ruling' => [
                ":: term\n:  detail\n  1. ordered\n    :: term\n    :  detail\n      1. ordered\n       q\n       > quote\n",
                '<dl><dt>term</dt><dd><p>detail1. ordered</p><dl><dt>term</dt><dd>detail</dd></dl><ol><li>orderedq</li></ol><blockquote><p>quote</p></blockquote></dd></dl>',
            ],
            'description host' => [
                ":: t\n:  d\n   :: u\n   :  e\n    1. x\n     q\n",
                '<dl><dt>t</dt><dd><p>d</p><dl><dt>u</dt><dd>e</dd></dl><ol><li>xq</li></ol></dd></dl>',
            ],
            'bullet in description' => [
                ":: t\n:  d\n   :: u\n   :  e\n    - x\n     q\n",
                '<dl><dt>t</dt><dd><p>d</p><dl><dt>u</dt><dd>e</dd></dl><ul><li>xq</li></ul></dd></dl>',
            ],
            'task in description' => [
                ":: t\n:  d\n   :: u\n   :  e\n    * [ ] x\n     q\n",
                '<dl><dt>t</dt><dd><p>d</p><dl><dt>u</dt><dd>e</dd></dl><ul class="task-list"><li><input type="checkbox" disabled aria-label="x q"> xq</li></ul></dd></dl>',
            ],
            'quote host' => [
                "> :: t\n> :  d\n>    :: u\n>    :  e\n>     1. x\n>      q\n",
                '<blockquote><dl><dt>t</dt><dd><p>d</p><dl><dt>u</dt><dd>e</dd></dl><ol><li>xq</li></ol></dd></dl></blockquote>',
            ],
            'item host' => [
                "- a\n  :: t\n  :  d\n     :: u\n     :  e\n      1. x\n",
                '<ul><li>a<dl><dt>t</dt><dd><p>d</p><dl><dt>u</dt><dd>e</dd></dl><ol><li>x</li></ol></dd></dl></li></ul>',
            ],
        ];
    }

    #[DataProvider('reachedDescriptions')]
    public function testReachedDescriptionOwnsTheNextBlock(string $source, string $expected): void
    {
        $html = (new CarveConverter())->convert($source);
        $flat = (string)preg_replace('/\n[ \t]*/', '', $html);
        self::assertSame($expected, preg_replace('/>\s+</', '><', $flat));
    }
}
