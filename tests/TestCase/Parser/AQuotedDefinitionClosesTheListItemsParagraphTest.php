<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AQuotedDefinitionClosesTheListItemsParagraphTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'ordered footnote' => [
                "1. a\n  :: t\n  :  d\n    > q\n    > [^n]: note\n q\n",
                "<ol>\n  <li>a\n:: t\n:  d\n    <blockquote><p>q</p></blockquote>\n  </li>\n</ol>\n<p>q</p>\n",
            ],
            'bullet reference' => [
                "- a\n  :: t\n  :  d\n    > q\n    > [n]: /u\n q\n",
                "<ul>\n  <li>a\n    <dl>\n      <dt>t</dt>\n      <dd>d</dd>\n    </dl>\n    <blockquote><p>q</p></blockquote>\n  </li>\n</ul>\n<p>q</p>\n",
            ],
            'task continued footnote' => [
                "- [ ] a\n  :: t\n  :  d\n    > q\n    > [^n]: note\n    >   more\n q\n",
                "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled aria-label=\"a\"> a\n    <dl>\n      <dt>t</dt>\n      <dd>d</dd>\n    </dl>\n    <blockquote><p>q</p></blockquote>\n  </li>\n</ul>\n<p>q</p>\n",
            ],
            'nested bullet' => [
                "- a\n  - b\n    :: t\n    :  d\n      > q\n      > [n]: /u\n q\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <dl>\n          <dt>t</dt>\n          <dd>d</dd>\n        </dl>\n        <blockquote><p>q</p></blockquote>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>q</p>\n",
            ],
            'one column control' => [
                "- a\n  > q\n  > [n]: /u\n q\n",
                "<ul>\n  <li>a\n    <blockquote><p>q</p></blockquote>\n  </li>\n</ul>\n<p>q</p>\n",
            ],
            'open quote paragraph' => [
                "- a\n  > q\n q\n",
                "<ul>\n  <li>a\n    <blockquote><p>q\nq</p></blockquote>\n  </li>\n</ul>\n",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testQuotedDefinitionOwnership(string $source, string $expected): void
    {
        $this->assertSame(preg_replace('/\n\z/', '', $expected), (new CarveConverter())->convert($source));
    }
}
