<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * `::: |` is a language-neutral alias for `::: line-block`: the pipe is the
 * block's type token on the opener, identical in every way to the keyword form.
 */
class LineBlockPipeAliasTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testPipeAliasRendersLineBlock(): void
    {
        $html = $this->converter->convert("::: |\nRoses are red,\nViolets are blue.\n:::");

        $this->assertSame(
            $this->converter->convert("::: line-block\nRoses are red,\nViolets are blue.\n:::"),
            $html,
        );
        $this->assertStringContainsString('<div class="line-block">', $html);
        $this->assertStringContainsString("Roses are red,<br>\nViolets are blue.", $html);
    }

    public function testPipeAliasPreservesIndentAndStanzas(): void
    {
        $html = $this->converter->convert("::: |\nflush\n  indented\n\nstanza two\n:::");

        $this->assertStringContainsString("flush<br>\n&nbsp;&nbsp;indented", $html);
        $this->assertSame(2, substr_count($html, '<p>'));
    }

    public function testPipeAliasAcceptsAttributes(): void
    {
        $html = $this->converter->convert("::: | {#poem}\none\ntwo\n:::");

        $this->assertStringContainsString('id="poem"', $html);
        $this->assertStringContainsString('line-block', $html);
    }
}
