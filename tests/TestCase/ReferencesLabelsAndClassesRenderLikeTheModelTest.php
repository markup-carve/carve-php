<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReferencesLabelsAndClassesRenderLikeTheModelTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function renderings(): array
    {
        $table = static fn (string $a, string $b): string => "<table>\n  <tbody>\n    <tr><td>$a</td><td>$b</td></tr>\n  </tbody>\n</table>";
        $note = static fn (string $label): string => "<aside class=\"admonition note\" aria-label=\"Note\">\n  <p class=\"div-label\">$label</p>\n  <p>body</p>\n</aside>";
        $captioned = static fn (string $link): string => "<table id=\"t\">\n  <caption>%s</caption>\n  <tbody>\n    <tr><td></td></tr>\n  </tbody>\n</table>\n<p>See <a href=\"#t\">$link</a>.</p>";

        return [
            'carve#4293 double left arrow' => ["# a <== b\n", "<section id=\"a-b\">\n  <h1>a ⇐ b</h1>\n</section>"],
            'carve#4293 double two-way arrow' => ["# a <=> b\n", "<section id=\"a-b\">\n  <h1>a ⇔ b</h1>\n</section>"],
            'carve#4349 dropped footnote reference id' => ["x[^n]{#i}\n\n# i\n", "<p>x[^n]</p>\n<section id=\"i\">\n  <h1>i</h1>\n</section>"],
            'carve#4349 dropped link reference id' => ["[a][nope]{#i}\n\n# i\n", "<p>[a][nope]{#i}</p>\n<section id=\"i\">\n  <h1>i</h1>\n</section>"],
            'carve#4310 escaped space before a comment' => ["::: note [x\\  %% c]\nbody\n:::\n", $note('x&nbsp;')],
            'carve#4137 invalid header-rows count' => ["{header-rows=7}\n|= a |\n| b |\n", "<table>\n  <tbody>\n    <tr><th scope=\"row\">a</th></tr>\n    <tr><td>b</td></tr>\n  </tbody>\n</table>"],
            'carve#4108 emphasis' => ["{#t}\n||\n^ *Fig* #: S\n\nSee </#t>.\n", sprintf($captioned('<strong>Fig</strong> 1'), '<strong>Fig</strong> 1: S')],
            'carve#4108 unresolved reference' => ["{#t}\n||\n^ [x][nope] #: S\n\nSee </#t>.\n", sprintf($captioned('[x][nope] 1'), '[x][nope] 1: S')],
            'carve#4108 image' => ["{#t}\n||\n^ ![x][u] #: S\n\nSee </#t>.\n\n[u]: a.png\n", sprintf($captioned('<img src="a.png" alt="x"> 1'), '<img src="a.png" alt="x"> 1: S')],
            'carve#4088 bare comment' => ["| a | b |\n+ x | %% y |\n", $table('a x', 'b')],
            'carve#4088 comment span' => ["| a | b |\n+ x | %%%y%%% |\n", $table('a x', 'b')],
            'carve#4088 braced comment keeps the join' => ["| a | b |\n+ x | {%%} |\n", $table('a x', 'b ')],
            'carve#4387 underscore' => ["---_\nk: v\n---\np\n", "<p>---_\nk: v</p>\n<hr>\n<p>p</p>"],
            'carve#4387 spaced underscore' => ["--- _\nk: v\n---\np\n", "<p>— _\nk: v</p>\n<hr>\n<p>p</p>"],
            'carve#4299 caption marker line' => ["|x|\n^ a\n  - b\n", "<table>\n  <caption>a\n  - b</caption>\n  <tbody>\n    <tr><td>x</td></tr>\n  </tbody>\n</table>"],
            '4311 comment before a description image' => [":: t\n: {%%}![a](x)\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <img src=\"x\" alt=\"a\">\n  </dd>\n</dl>"],
            '4311 attributes on that image' => [":: t\n: {.c}\n  {%%}![a](x)\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <img src=\"x\" alt=\"a\" class=\"c\">\n  </dd>\n</dl>"],
            '4314 leading comment' => ["{.c}\n{%%}![a](x.png)\n", '<img src="x.png" alt="a" class="c">'],
            '4314 trailing comment' => ["{.c}\n![a](x.png){.d}{%%}\n", '<img src="x.png" alt="a" class="c d">'],
            '4298 note reachable only through a literal label' => ["[[^1]][]\n[^1]: n\n::: footnotes\n", "<p>[[^1]][]</p>\n<div class=\"footnotes\">\n\n</div>"],
            '4298 the marker keeps its body' => ["[[^1]][]\n[^1]: n\n::: footnotes\nbody\n:::\n", "<p>[[^1]][]</p>\n<div class=\"footnotes\">\n  <p>body</p>\n</div>"],
        ];
    }

    #[DataProvider('renderings')]
    public function testRendersLikeTheModel(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testADefinitionInALosingDuplicateNoteIsCollected(): void
    {
        $html = (new CarveConverter())->convert("[^g]: a\n\n[^g]: b\n  [^h]: inner\n\nx[^g] [^h]\n");

        $this->assertStringContainsString('<p>inner<a href="#fnref2"', $html);
        $this->assertStringNotContainsString('<p>b', $html);
        $this->assertStringNotContainsString('[^h]', $html);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function promotedImages(): array
    {
        return [
            'merged run' => ["{.outer k=one}\n![a](b){.inner k=two}\n", 1],
            'reference image' => ["{.o}\n![a][r]\n\n[r]: b.png\n", 1],
            'list item' => ["- {#i}\n  ![a](b.png){.x}\n", 1],
            'glued only' => ["![alt](img.png){.x}\n", 0],
            'empty run' => ["{}\n![a](b)\n", 0],
        ];
    }

    #[DataProvider('promotedImages')]
    public function testAPromotedImageReportsOnlyItsBlockRun(string $source, int $losses): void
    {
        $result = CarveConverter::markdown()->convertWithReport($source);

        $this->assertSame($losses, $result->lossCounts['markdown-attributes-dropped'] ?? 0);
    }
}
