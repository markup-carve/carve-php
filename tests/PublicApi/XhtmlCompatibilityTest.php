<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\PublicApi;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Cli\Command;
use PHPUnit\Framework\TestCase;

final class XhtmlCompatibilityTest extends TestCase
{
    public function testGeneratedVoidElementsUseXhtmlClosers(): void
    {
        $cases = [
            '***' => '<hr />',
            "a\\\nb" => "<p>a<br />\nb</p>",
            '![alt](pic.png)' => '<img src="pic.png" alt="alt" />',
        ];
        foreach ($cases as $source => $expected) {
            self::assertSame($expected, Carve::render($source, ['xhtml' => true]));
            self::assertSame(str_replace(' />', '>', $expected), Carve::render($source));
        }
        self::assertSame('<p>a <img src="pic.png" alt="alt" /></p>', Carve::render('a ![alt](pic.png)', ['xhtml' => true]));
    }

    public function testRoundtripPresenceAttributesHaveValues(): void
    {
        self::assertStringContainsString('data-carve-generated-id=""', Carve::render('# H', ['roundtrip' => true, 'xhtml' => true]));
    }

    public function testXhtmlFlagAndRawHtmlPreservation(): void
    {
        foreach (['-x', '--xhtml'] as $flag) {
            $in = fopen('php://memory', 'r+');
            $out = fopen('php://memory', 'r+');
            $err = fopen('php://memory', 'r+');
            self::assertNotFalse($in);
            self::assertNotFalse($out);
            self::assertNotFalse($err);
            fwrite($in, "***\n");
            rewind($in);
            self::assertSame(0, Command::main(['render', $flag, '-'], $in, $out, $err));
            rewind($out);
            self::assertSame("<hr />\n", stream_get_contents($out));
            fclose($in);
            fclose($out);
            fclose($err);
        }
        self::assertSame('<p><br></p>', Carve::render('`<br>`{=html}', ['xhtml' => true, 'rawHtml' => true]));
    }
}
