<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The engine's own mention and hashtag output -
 * `<span class="mention"><strong>@alice</strong></span>` and the `tag`
 * shape around `#tag` - imports back as the bare sigil spelling.
 *
 * As an attributed span (`[*@alice*]{.mention}`) it double-wrapped on
 * reparse: the inner `@alice` parsed as a mention again, adding a layer per
 * HTML round trip (carve-php#1291). An authored span that merely carries
 * the class stays a span: the shortcut requires the whole text to be one
 * sigil token.
 */
class AMentionSpanImportsFromItsOwnHtmlTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function sourceProvider(): array
    {
        return [
            'mention and hashtag' => ["Ping @alice and tag #release-notes done\n"],
            'an email is not a mention' => ["email a@b.com stays\n"],
            'an authored span with the class stays a span' => ["[styled]{.mention}\n"],
        ];
    }

    /**
     * @param string $source
     */
    #[DataProvider('sourceProvider')]
    public function testTheRenderedHtmlSurvivesTheImport(string $source): void
    {
        $converter = new CarveConverter();
        $html = $converter->convert($source);
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertSame(
            $html,
            (new CarveConverter())->convert($imported),
            "importing the rendered HTML must reproduce it; imported source was:\n" . $imported,
        );
    }

    public function testTheImportedSourceSpellsTheSigils(): void
    {
        $html = (new CarveConverter())->convert("Ping @alice and tag #release-notes done\n");
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertStringContainsString('@alice', $imported);
        $this->assertStringContainsString('#release-notes', $imported);
        $this->assertStringNotContainsString('{.mention}', $imported);
        $this->assertStringNotContainsString('{.tag}', $imported);
    }
}
