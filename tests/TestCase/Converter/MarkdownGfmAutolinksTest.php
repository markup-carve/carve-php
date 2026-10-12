<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Node\Inline\Math;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownGfmAutolinksTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function controls(): iterable
    {
        $rows = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/markdown-gfm-autolinks.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($rows as $index => $row) {
            yield 'native ' . $index => [$row['source'], $row['html']];
        }
    }

    #[DataProvider('controls')]
    public function testNativeAutolinkMeaningSurvivesImportAndSourceFormatting(string $markdown, string $html): void
    {
        $migration = (new MarkdownToCarve())->convertWithFidelityReport($markdown);
        $converter = new CarveConverter();
        $expected = self::htmlShape($html);
        self::assertSame($expected, self::htmlShape($converter->convert($migration->value)));
        self::assertSame($expected, self::htmlShape($converter->convert(CarveConverter::toCarve($migration->value))));
        foreach ($migration->diagnostics as $row) {
            self::assertFalse($row->confidence === 'exact' && $row->fidelity === 'dropped');
        }
    }

    #[DataProvider('slashPreflightControls')]
    public function testDelimiterRunsPreserveSlashMeaning(string $markdown, string $html): void
    {
        $written = (new MarkdownToCarve())->convert($markdown);
        self::assertSame($html . "\n", (new CarveConverter())->convert($written));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function slashPreflightControls(): iterable
    {
        yield 'strong with destination' => ['A **strong** phrase with [link](/target).', '<p>A <strong>strong</strong> phrase with <a href="/target">link</a>.</p>'];
        yield 'underscore strong with slashes' => ['__strong__ /literal/', '<p><strong>strong</strong> /literal/</p>'];
        yield 'even nested runs' => ['****strong**** /literal/', '<p><strong><strong>strong</strong></strong> /literal/</p>'];
        yield 'odd nested runs' => ['***strong*** /literal/', '<p><em><strong>strong</strong></em> /literal/</p>'];
        yield 'single emphasis' => ['*em* /literal/', '<p><em>em</em> /literal/</p>'];
        yield 'html emphasis' => ['<em>em</em> /literal/', '<p><em>em</em> /literal/</p>'];
        yield 'unmatched odd run' => ['***unclosed /literal/', '<p>***unclosed /literal/</p>'];
        yield 'mixed even runs' => ['**outer __inner__** /literal/', '<p><strong>outer <strong>inner</strong></strong> /literal/</p>'];
        yield 'mixed odd and even runs' => ['**a** _b_ /x/', '<p><strong>a</strong> <em>b</em> /x/</p>'];
        yield 'escaped even run' => ['\\** a ** /x/', '<p>** a ** /x/</p>'];
        yield 'even runs in label' => ['[**a**](/t) __b__', '<p><a href="/t"><strong>a</strong></a> <strong>b</strong></p>'];
        yield 'intraword even runs' => ['**a**b** /x/', '<p><strong>a</strong>b** /x/</p>'];
    }

    public function testOptInMathKeepsReferenceChainsLiteral(): void
    {
        foreach (['$', '$$'] as $delimiter) {
            $written = (new MarkdownToCarve(convertMath: true))->convert($delimiter . '[a*b*][x](/u)' . $delimiter);
            $document = (new CarveConverter())->parse($written);
            $math = $document->getChildren()[0]->getChildren()[0];
            self::assertInstanceOf(Math::class, $math);
            self::assertSame('[a*b*][x](/u)', $math->getContent());
        }
    }

    public function testOptInHighlightKeepsGeneratedDelimitersSeparateFromLiteralSymbols(): void
    {
        $written = (new MarkdownToCarve(convertHighlight: true))->convert('==Done!==');
        self::assertSame('<p><mark>Done!</mark></p>' . "\n", (new CarveConverter())->convert($written));
    }

    /**
     * @return array<mixed>
     */
    private static function htmlShape(string $html): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"?><html><body>' . $html . '</body></html>');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $visit = static function (DOMNode $node, bool $literal = false) use (&$visit): mixed {
            if ($node instanceof DOMText) {
                return $literal ? $node->data : preg_replace('/[\t\n\r\f ]+/', ' ', $node->data);
            }
            if (!$node instanceof DOMElement) {
                return null;
            }
            $name = match ($node->tagName) {
                'del' => 's',
                'i' => 'em',
                default => $node->tagName,
            };
            $literal = $literal || $name === 'pre' || $name === 'code';
            $structural = in_array($name, ['body', 'blockquote', 'ul', 'ol', 'dl', 'table', 'thead', 'tbody', 'tr'], true);
            $children = [];
            foreach ($node->childNodes as $child) {
                if ($structural && $child instanceof DOMText && preg_match('/^[\t\n\r\f ]*$/', $child->data) === 1) {
                    continue;
                }
                $part = $visit($child, $literal);
                if ($part !== null) {
                    $last = array_key_last($children);
                    if (is_string($part) && $last !== null && is_string($children[$last])) {
                        $joined = $children[$last] . $part;
                        $children[$last] = $literal ? $joined : (preg_replace('/[ \t\n\r\f]+/', ' ', $joined) ?? $joined);
                    } else {
                        $children[] = $part;
                    }
                }
            }
            $attrs = [];
            foreach ($node->attributes as $attr) {
                if ($name === 'th' && $attr->name === 'scope' && $attr->value === 'col') {
                    continue;
                }
                $attrs[$attr->name] = $attr->value;
            }
            ksort($attrs);
            if ($name === 'p' && count($children) === 1 && is_array($children[0]) && $children[0][0] === 'img') {
                return $children[0];
            }

            return [$name, $attrs, $children];
        };

        return $visit($document->getElementsByTagName('body')->item(0));
    }

    public function testEmailAutolinkingKeepsTrailingNewlineConvention(): void
    {
        foreach (['', "\n", "\n\n"] as $ending) {
            $source = (new MarkdownToCarve())->convert('a@example.test' . $ending);
            self::assertSame($ending !== '', str_ends_with($source, "\n"));
            self::assertSame(self::htmlShape('<p><a href="mailto:a@example.test">a@example.test</a></p>'), self::htmlShape((new CarveConverter())->convert($source)));
        }
    }

    public function testEmailTextInsideFootnoteDefinitionsIsLinked(): void
    {
        foreach (['a@example.test', 'a\\_b@example.test', 'a+-b@example.test'] as $email) {
            $migration = (new MarkdownToCarve())->convertWithFidelityReport("note[^n]\n\n[^n]: " . $email . "\n");
            $address = str_replace('\\', '', $email);
            $expected = '<a href="mailto:' . $address . '">' . $address . '</a>';
            $converter = new CarveConverter();
            self::assertStringContainsString($expected, $converter->convert($migration->value));
            self::assertStringContainsString($expected, $converter->convert(CarveConverter::toCarve($migration->value)));
            self::assertContains('fidelity-unverified', array_column($migration->diagnostics, 'code'));
        }
    }

    public function testSlashGuardsKeepMathBodiesLiteral(): void
    {
        foreach (['$a/b$', '$$a/b$$'] as $math) {
            $carve = (new MarkdownToCarve(convertMath: true))->convert('*Note:* ' . $math);
            self::assertStringContainsString('`a/b`', $carve);
            self::assertStringNotContainsString('a\\/b', $carve);
        }
    }

    public function testSlashInAnAngleEmailKeepsTheWholeAddress(): void
    {
        $carve = (new MarkdownToCarve())->convert('*x* <a/b@example.test>');
        self::assertSame(self::htmlShape('<p><em>x</em> <a href="mailto:a/b@example.test">a/b@example.test</a></p>'), self::htmlShape((new CarveConverter())->convert($carve)));
    }

    public function testEmailPassKeepsBreaksAfterFrontmatter(): void
    {
        $markdown = "---\ntitle: x\n---\n---\n\nmail a@example.test\n\n---\n\nmore b@example.test\n";
        $carve = (new MarkdownToCarve())->convert($markdown);
        $html = (new CarveConverter())->convert($carve);
        self::assertSame(2, substr_count($html, '<hr>'));
        self::assertStringContainsString('<a href="mailto:a@example.test">a@example.test</a>', $html);
        self::assertStringContainsString('<a href="mailto:b@example.test">b@example.test</a>', $html);
        self::assertStringNotContainsString('---yaml', $html);
    }

    public function testEditorialCommentsKeepLiteralExportedText(): void
    {
        foreach (['see http://example.com', 'x <b> & *y* b', 'x &lt; y', 'x &#92;* y'] as $text) {
            $carve = 'a {#' . $text . "#} b\n";
            $markdown = CarveConverter::markdown()->convert($carve);
            self::assertSame($carve, (new MarkdownToCarve())->convert($markdown));
        }
        self::assertSame("{#see http://example.com#}\n", (new MarkdownToCarve())->convert("<span class=\"critic-comment\">see http://example.com</span>\n"));
    }

    public function testEditorialCommentsKeepProtectedHtmlRaw(): void
    {
        foreach (['a <!-- c --> b', '<code>x</code>'] as $body) {
            $carve = (new MarkdownToCarve())->convert('<span class="critic-comment">' . $body . "</span>\n");
            self::assertStringContainsString('{=html}', $carve);
            self::assertStringNotContainsString('{#', $carve);
        }
    }

    public function testEmailPassKeepsBreaksInsideFootnotes(): void
    {
        $markdown = "note[^n]\n\n[^n]: ---\n\n    mail a@example.test\n\n    ---\n\n    more b@example.test\n";
        $carve = (new MarkdownToCarve())->convert($markdown);
        $html = (new CarveConverter())->convert($carve);
        self::assertStringNotContainsString('---yaml', $html);
        self::assertSame(3, substr_count($html, '<hr>'));
        self::assertStringContainsString('<a href="mailto:a@example.test">a@example.test</a>', $html);
        self::assertStringContainsString('<a href="mailto:b@example.test">b@example.test</a>', $html);
    }

    public function testBasicBareLinksHaveExactReports(): void
    {
        foreach (['http://example.test', 'https://example.test/a', 'FTP://example.test/a', 'www.example.test', 'a@example.test'] as $source) {
            $diagnostics = (new MarkdownToCarve())->convertWithFidelityReport($source)->diagnostics;
            self::assertContains('markdown-bare-autolink', array_column($diagnostics, 'code'));
            foreach ($diagnostics as $row) {
                self::assertSame('exact', $row->confidence);
                self::assertNotSame('dropped', $row->fidelity);
            }
        }
    }
}
