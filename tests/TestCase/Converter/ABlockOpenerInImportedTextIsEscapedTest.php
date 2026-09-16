<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Text that starts a line with a block opener is escaped as the Carve writer
 * escapes it, so it reads back as text (markup-carve/carve-php#2074).
 */
class ABlockOpenerInImportedTextIsEscapedTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function importProvider(): array
    {
        return [
            'a bullet' => ['<p>- x</p>', '\\- x'],
            'a heading' => ['<p># x</p>', '\\# x'],
            'a heading run' => ['<p>## x</p>', '\\#\\# x'],
            'an ordered marker' => ['<p>1. x</p>', '1\\. x'],
            'a letter marker' => ['<p>a) x</p>', 'a\\) x'],
            'a quote' => ['<p>&gt; x</p>', '\\> x'],
            'a table row' => ['<p>| x |</p>', '\\| x |'],
            'a definition term' => ['<p>:: x</p>', '\\:: x'],
            'a thematic break' => ['<p>---</p>', '\\-\\-\\-'],
            'a spaced thematic break' => ['<p>* * *</p>', '\\* * *'],
            'a tilde fence' => ['<p>~~~ x</p>', '\\~\\~\\~ x'],
            'a div fence' => ['<p>::: note</p>', '\\::: note'],
            'a footnote definition' => ['<p>[^1]: n</p>', '\\[^1]: n'],
            'a reference definition' => ['<p>[a]: /u</p>', '\\[a]: /u'],
            'an abbreviation definition' => ['<p>*[a]: b</p>', '\\*[a]: b'],
            'an attribute line' => ['<p>{.c}</p>', '\\{.c}'],
            'a comment opener' => ['<p>%% x</p>', '\\%\\% x'],
            'a task item' => ['<p>- [ ] x</p>', '\\- [ ] x'],
            'an unwrapped anchor' => ['<p><a href="">- x</a></p>', '\\- x'],
            'after an empty element' => ['<p><b></b># x</p>', '\\# x'],
            'after a hard break' => ['<p>a<br>&gt; x</p>', "a\\\n\\> x"],
            'a bare text run' => ['<div>- x</div>', '\\- x'],
            'a list item' => ['<ul><li>- x</li></ul>', '- \\- x'],
            'a list item after a hard break' => ['<ul><li>a<br>- x</li></ul>', "- a\\\n  \\- x"],
            'a lone plus in a list item' => ['<ul><li>+</li></ul>', '- \\+'],
            'a description' => ['<dl><dt>t</dt><dd># x</dd></dl>', ":: t\n: \\# x"],
            'a lone plus below the first part of an item' => ['<ul><li>a<div>+</div></li></ul>', "- a\n\n  +"],
            'a comment that starts the paragraph' => ['<p><!-- c -->- x</p>', '{%  c  %}- x'],
            'a lone plus in a description' => ['<dl><dt>t</dt><dd>+</dd></dl>', ":: t\n: \\+"],
            'text in a quote' => ['<blockquote>1. x</blockquote>', '> 1\\. x'],
            'no opener' => ['<p>x - y</p>', 'x - y'],
            'a plus with text' => ['<p>+ x</p>', '+ x'],
            'a lone plus in a paragraph' => ['<p>+</p>', '+'],
            'a hyphen word' => ['<p>-x</p>', '-x'],
            'a sentence abbreviation' => ['<p>Mr. Smith</p>', 'Mr. Smith'],
            'a bullet after a hard break at the top level' => ['<p>a<br>- x</p>', "a\\\n- x"],
            'a strong that starts the paragraph' => ['<p><strong>- x</strong></p>', '*- x*'],
            'a span label bracket' => ['<p><span class="k">[a</span></p>', '[\\[a]{.k}'],
            'a rule inside an unwrapped element' => ['<button><hr></button>', '---'],
        ];
    }

    #[DataProvider('importProvider')]
    public function testTheImportIsWritten(string $html, string $carve): void
    {
        $this->assertSame($carve . "\n", (new HtmlToCarve())->convert($html));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function sweepProvider(): array
    {
        $openers = [
            '-', '*', '+', '#', '##', '######', '#######', '>', '>>', '|',
            ':', '::', ':::', '::::', '%%', '%%%',
            '~~~', '~~~~', '```', '***', '___', '- - -', '* * *', '1.', '1)', '12.',
            'a.', 'A)', 'iv.',
            '(a)', '[a]:', '*[a]:', '{.c}', '{k=v}', '- [ ]', '- [x]', '|=', '|x|', '[[',
            '||', 'Mr.',
        ];
        $contexts = [
            'paragraph' => ['<p>%s</p>', '%s'],
            'hard break' => ['<p>a<br>%s</p>', "a\\\n%s"],
            'span' => ['<p><span>%s</span></p>', '%s'],
            'list item' => ['<ul><li>%s</li></ul>', '- %s'],
            'list item hard break' => ['<ul><li>a<br>%s</li></ul>', "- a\\\n  %s"],
            'list item paragraph' => ['<ul><li><p>%s</p></li></ul>', "{loose}\n- %s"],
            'ordered item' => ['<ol><li>%s</li></ol>', '1. %s'],
            'nested item' => ['<ul><li>a<ul><li>%s</li></ul></li></ul>', "- a\n  - %s"],
            'quote' => ['<blockquote>%s</blockquote>', '> %s'],
            'quote paragraph' => ['<blockquote><p>%s</p></blockquote>', '> %s'],
            'quote in an item' => ['<ul><li><blockquote>%s</blockquote></li></ul>', '- > %s'],
            'description' => ['<dl><dt>t</dt><dd>%s</dd></dl>', ":: t\n: %s"],
            'description paragraph' => ['<dl><dt>t</dt><dd><p>%s</p></dd></dl>', ":: t\n: %s"],
            'description hard break' => ['<dl><dt>t</dt><dd>a<br>%s</dd></dl>', ":: t\n: a\\\n  %s"],
            'div' => ['<div>%s</div>', '%s'],
        ];

        $cases = [];
        foreach ($contexts as $name => [$html, $carve]) {
            foreach ($openers as $opener) {
                $cases[$name . ': ' . $opener] = [sprintf($html, htmlspecialchars($opener, ENT_NOQUOTES)), $carve, $opener];
                $text = $opener . ' x';
                $cases[$name . ': ' . $text] = [sprintf($html, htmlspecialchars($text, ENT_NOQUOTES)), $carve, $text];
            }
        }

        return $cases;
    }

    /**
     * The writer spells the same tree, so its bytes are the expectation. The
     * text is escaped in full for the reference and the writer relaxes what
     * the context does not need.
     */
    #[DataProvider('sweepProvider')]
    public function testTheImportMatchesTheWriter(string $html, string $carve, string $text): void
    {
        $escaped = $text === '+' ? '\\+' : (string)preg_replace('/[^A-Za-z0-9 ]/', '\\\\$0', $text);
        $expected = (new CarveRenderer())->render($this->unescaped(sprintf($carve, $escaped)));

        $imported = (new HtmlToCarve())->convert($html);

        $this->assertSame($expected, $imported);
        $this->assertStringContainsString(
            htmlspecialchars($text, ENT_NOQUOTES),
            (new CarveConverter())->convert($imported),
        );
    }

    /**
     * The tree the source means, with every escaped character turned back into
     * plain text so the writer decides each escape itself.
     */
    protected function unescaped(string $source): Document
    {
        $codec = new AstCodec();
        $merge = static function (array $node) use (&$merge): array {
            unset($node['pos']);
            foreach (['children', 'items'] as $key) {
                if (!isset($node[$key])) {
                    continue;
                }
                $merged = [];
                foreach ($node[$key] as $child) {
                    $child = $merge($child);
                    if ($child['type'] === 'escaped_text') {
                        $child = ['type' => 'text', 'value' => $child['value']];
                    }
                    $last = array_key_last($merged);
                    if ($child['type'] === 'text' && $last !== null && $merged[$last]['type'] === 'text') {
                        $merged[$last]['value'] .= $child['value'];

                        continue;
                    }
                    $merged[] = $child;
                }
                $node[$key] = $merged;
            }

            return $node;
        };

        return $codec->decode($merge($codec->encode(CarveConverter::create()->parse($source))));
    }
}
