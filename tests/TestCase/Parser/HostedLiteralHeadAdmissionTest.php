<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class HostedLiteralHeadAdmissionTest extends TestCase
{
    public function testMarkerKindsAndPipePaddingKeepTheDescendantScope(): void
    {
        $converter = new CarveConverter();
        foreach (['- ' => 2, '10. ' => 4, 'a. ' => 3, 'iv) ' => 4, '- [ ] ' => 2] as $marker => $width) {
            $body = str_repeat(' ', 2 + $width);
            $source = "> - ::: box\n>   " . $marker . "::: |\n> " . $body . "```\n> " . $body
                . ":::\n>    ```\n>    :::bad\n>    :::\n- tail\n";
            $html = $converter->convert($source);
            if ($marker !== '- [ ] ') {
                self::assertStringContainsString("<pre><code>:::bad\n:::\n</code></pre>", $html, $marker);
            } else {
                self::assertStringContainsString("<p><code>\n:::bad\n:::</code></p>", $html, $marker);
            }
            self::assertSame(preg_replace('/\n\z/', '', $html), $converter->convert(str_replace('::: |', ':::  |', $source)), $marker);
        }
    }

    public function testAnAnnotatedPipeDoesNotOpenALiteralContainer(): void
    {
        $html = (new CarveConverter())->convert("- ::: box\n  - ::: | [id]\n    body\n  :::\n");
        self::assertStringNotContainsString('class="line-block"', $html);
        self::assertStringContainsString('body', $html);
    }

    public function testWhitespaceOnlyBlankKeepsTheSameBoundary(): void
    {
        $source = "> - ::: box\n>   text\n>\n>   - ::: |\n>     :::\n>    ```\nlazy\n";
        $converter = new CarveConverter();
        self::assertSame(
            $converter->convert($source),
            $converter->convert(str_replace("\n>\n", "\n>   \t\n", $source)),
        );
    }

    public function testHeadingBytesInAnOpenCodeBodyStayPayload(): void
    {
        $source = "- a\n\n    ```\n  # x\n    y\n";
        $html = (new CarveConverter())->convert($source);
        self::assertStringContainsString("<pre><code># x\ny\n</code></pre>", $html);
        self::assertStringNotContainsString('<h1', $html);
    }

    public function testAHostedQuoteReleasesDescriptionsAfterEveryTermMarker(): void
    {
        $converter = new CarveConverter();
        foreach (['', '- ', 'a. ', 'iv) ', '- [ ] ', '-{.c} '] as $marker) {
            $source = '- > ' . $marker . ":: t\n  : body\n";
            $html = $converter->convert($source);
            self::assertStringNotContainsString('<dd>', $html, $marker);
            self::assertStringContainsString("</blockquote>\n    : body", $html, $marker);
        }
    }
}
