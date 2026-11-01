<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Expected HTML is the spec model's (PART 9 §17 L1b, carve#4639 ruling B).
 */
final class ABlankAfterAnInvisibleItemLeadKeepsTheListTightTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'a reference definition lead (carve#4724)' => ["- [1]:\n\n  q", "<ul>\n  <li>q</li>\n</ul>"];
        yield 'a fenced comment lead (carve#4639)' => ["- %%%\n  h\n  %%%\n\n  q", "<ul>\n  <li>q</li>\n</ul>"];
        yield 'a fenced comment lead with text on its opener' => ["- %%% note\n  h\n  %%%\n\n  q", "<ul>\n  <li>q</li>\n</ul>"];
        yield 'a fenced comment lead with text on its closer' => ["- %%%\n  h\n  %%% tail\n\n  q", "<ul>\n  <li>q</li>\n</ul>"];
        yield 'text above a fenced comment still loosens' => ["- x\n  %%%\n  h\n  %%%\n\n  q", "<ul>\n  <li><p>x</p>\n    <p>q</p>\n  </li>\n</ul>"];
        yield 'a comment lead' => ["- %% c\n\n  q", "<ul>\n  <li>q</li>\n</ul>"];
        yield 'an attribute line lead' => ["- {.c}\n\n  Body.\n- x", "<ul>\n  <li><p class=\"c\">Body.</p></li>\n  <li>x</li>\n</ul>"];
        yield 'a task item with a comment lead' => ["- [ ] %%\n\n  q", "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled aria-label=\"q\"> q</li>\n</ul>"];
        yield 'a visible lead still loosens' => ["- x\n\n  q", "<ul>\n  <li><p>x</p>\n    <p>q</p>\n  </li>\n</ul>"];
    }

    #[DataProvider('documents')]
    public function testRender(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
