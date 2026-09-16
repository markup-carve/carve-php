<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#2022. `bare_opener(d)` refuses a delimiter followed by whitespace
 * or by the same delimiter, and nothing else. A `}` refused the opener here,
 * so the second run of a triple-nested brace never formed and the Markdown
 * target wrote its tail as literal characters.
 */
class ADelimiterAgainstACloseBraceStillOpensTest extends TestCase
{
    private CarveConverter $converter;

    private CarveConverter $markdown;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
        $this->markdown = CarveConverter::markdown();
    }

    private function html(string $source): string
    {
        return rtrim($this->converter->convert($source), "\n");
    }

    private function md(string $source): string
    {
        return rtrim($this->markdown->convert($source), "\n");
    }

    public function testATripleNestedBraceBuildsItsSecondRun(): void
    {
        $this->assertSame('<p><em>{/{/x</em><em>}</em>}</p>', $this->html("{/{/{/x/}/}/}\n"));
        $this->assertSame(
            '<p><strong>{*{*x</strong><strong>}</strong>}</p>',
            $this->html("{*{*{*x*}*}*}\n"),
        );
        $this->assertSame('<p><mark>{={=x</mark><mark>}</mark>}</p>', $this->html("{={={=x=}=}=}\n"));
    }

    public function testTheMarkdownTargetKeepsTheSecondRun(): void
    {
        $this->assertSame('*{/{/x*<em>}</em>}', $this->md("{/{/{/x/}/}/}\n"));
        $this->assertSame('**{\\*{\\*x**<strong>}</strong>}', $this->md("{*{*{*x*}*}*}\n"));
        $this->assertSame('<mark>{={=x</mark><mark>}</mark>}', $this->md("{={={=x=}=}=}\n"));
    }

    public function testABareDelimiterOpensAgainstACloseBrace(): void
    {
        $this->assertSame('<p><em>}</em></p>', $this->html("/}/\n"));
        $this->assertSame('<p><u>}b</u></p>', $this->html("_}b_\n"));
        $this->assertSame('<p><s>}}</s></p>', $this->html("~}}~\n"));
    }

    public function testTheGuardsThatDoRefuseStillRefuse(): void
    {
        // Whitespace after the opener, an alphanumeric after the closer, and
        // same-delimiter adjacency.
        $this->assertSame('<p>/ }/</p>', $this->html("/ }/\n"));
        $this->assertSame('<p>a/}/b</p>', $this->html("a/}/b\n"));
        $this->assertSame('<p>//}//</p>', $this->html("//}//\n"));
    }

    public function testTheBracedFormReadsTheSameContent(): void
    {
        $this->assertSame('<p><em>}</em></p>', $this->html("{/}/}\n"));
        $this->assertSame('<p><u>}b</u></p>', $this->html("{_}b_}\n"));
    }
}
