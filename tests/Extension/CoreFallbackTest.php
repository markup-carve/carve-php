<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Extension;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\Bundle;
use MarkupCarve\Carve\Extension\Details;
use MarkupCarve\Carve\Extension\ListTable;
use MarkupCarve\Carve\Extension\Spoiler;
use MarkupCarve\Carve\Extension\Tabs;
use MarkupCarve\Carve\Extension\Wikilinks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve-js#5775 (test/extension-core-fallback.test.ts): a claimed body that holds an inline extension is
 * claimed, and its fallback holds core content only (C2-X-022).
 */
final class CoreFallbackTest extends TestCase
{
    /**
     * @return array<string, array{string, array<mixed>, string}>
     */
    public static function shapes(): array
    {
        return [
            'details' => [
                "::: details \"X\"\nY :wiki[Z].\n:::\n", [Bundle::merge(Details::bundle(), Wikilinks::bundle())],
                "<details>\n  <summary>X</summary>\n  <p>Y <a href=\"z\" class=\"wikilink\" data-wikilink=\"Z\">Z</a>.</p>\n</details>",
            ],
            'tabs' => [
                ":::: tabs\n::: tab \"A\"\nY :foo[Z].\n:::\n::::\n", Tabs::extensions(true),
                '<div class="tabs" role="group" aria-label="Tabs"><input type="radio" name="tabs-1" id="tabs-1-0" checked>'
                . '<label for="tabs-1-0">A</label><div class="tab-panel" role="group" aria-label="A">'
                . '<p>Y <span class="ext-foo">Z</span>.</p></div></div>',
            ],
            'spoiler' => [
                "::: spoiler\nY :foo[Z].\n:::\n", [Spoiler::bundle()],
                "<details class=\"spoiler\">\n  <summary>Spoiler</summary>\n  <p>Y <span class=\"ext-foo\">Z</span>.</p>\n</details>",
            ],
            'list-table' => [
                "::: list-table\n- - a :foo[Z]\n  - b\n:::\n", [ListTable::bundle()],
                "<table>\n  <tbody>\n    <tr><td>a <span class=\"ext-foo\">Z</span></td><td>b</td></tr>\n  </tbody>\n</table>",
            ],
        ];
    }

    /**
     * @param string $source
     * @param array<mixed> $extensions
     * @param string $html
     */
    #[DataProvider('shapes')]
    public function testClaimsTheBlockWithoutADiagnostic(string $source, array $extensions, string $html): void
    {
        $result = Carve::renderWithDiagnostics($source, ['extensions' => $extensions]);
        $this->assertSame([], $result->diagnostics);
        $this->assertSame($html, $result->html);
        $json = Carve::encodeTree(Carve::parse($source, ['extensions' => $extensions]));
        $this->assertStringNotContainsString('"inline_extension"', (string)json_encode(self::at($json, '/children/0/fallback')));
    }

    public function testAnUnqualifiedInlineExtensionInTheFallbackIsItsCoreSpan(): void
    {
        $extensions = [Details::bundle()];
        $tree = Carve::parse("::: details \"X\"\nY :foo[Z]{#i .c}.\n:::\n", ['extensions' => $extensions]);
        $json = Carve::encodeTree($tree);
        $this->assertSame('block_extension', self::at($json, '/children/0/type'));
        $this->assertSame(
            '{"children":[{"type":"text","value":"Y "},'
            . '{"attrs":{"classes":["ext-foo","c"],"id":"i"},"children":[{"type":"text","value":"Z"}],"type":"span"},'
            . '{"type":"text","value":"."}],"type":"paragraph"}',
            json_encode(self::at($json, '/children/0/fallback/1')),
        );
        $this->assertSame('inline_extension', self::at($json, '/children/0/slots/body/0/children/1/type'));
        Carve::validateTree($tree, ['extensions' => $extensions]);
    }

    /**
     * The value at `$pointer` of the encoded tree, without positions.
     */
    private static function at(string $json, string $pointer): mixed
    {
        $value = json_decode($json, flags: JSON_THROW_ON_ERROR);
        foreach (array_slice(explode('/', $pointer), 1) as $part) {
            if (is_array($value)) {
                $value = $value[(int)$part] ?? null;
            } elseif (is_object($value)) {
                $value = $value->{$part} ?? null;
            }
        }

        return self::strip($value);
    }

    private static function strip(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::strip(...), $value);
        }
        if (!is_object($value)) {
            return $value;
        }
        $out = [];
        foreach (get_object_vars($value) as $key => $item) {
            if ($key !== 'pos') {
                $out[$key] = self::strip($item);
            }
        }

        return (object)$out;
    }
}
