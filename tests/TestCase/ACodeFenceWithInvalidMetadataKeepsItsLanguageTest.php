<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Lint\SourceLinter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Rows follow tests/a-code-fence-with-invalid-metadata-keeps-its-language.test.mjs
 * in markup-carve/carve (carve#4807), plus the lint rulings of carve#4849.
 */
class ACodeFenceWithInvalidMetadataKeepsItsLanguageTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function recoveredProvider(): array
    {
        $rows = [];
        foreach (['```', '~~~', '````'] as $run) {
            foreach (['js title="x"', 'js {1,3}', 'js{1,3}', 'php {.x}', 'php [tab] "title"', 'js"title"', 'js[label]', 'js "title"[label]', 'js "T" junk', "js\t\"T\"", 'js “x”', 'js x'] as $info) {
                $rows[$run . $info] = [$run, $info];
            }
        }

        return $rows;
    }

    #[DataProvider('recoveredProvider')]
    public function testAValidTokenKeepsTheBlockAndDropsTheMetadata(string $run, string $info): void
    {
        $language = (string)preg_replace('/[^\w-].*$/su', '', $info);
        $expected = "<pre><code class=\"language-{$language}\">payload\n</code></pre>";

        $this->assertSame($expected, (new CarveConverter())->convert("{$run}{$info}\npayload\n{$run}\n"));
        $this->assertSame("<p>lead</p>\n{$expected}", (new CarveConverter())->convert("lead\n{$run}{$info}\npayload\n{$run}\n"));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function fallbackProvider(): array
    {
        return [
            'comma ends the token' => ['```js,title="x"'],
            'no token' => ['``` {.x}'],
            'tab before the token' => ["```\tjs"],
            'two spaces before the token' => ['```  js'],
            'equals ends the token' => ['```js=html'],
            'glued label after a leading title' => ['```"title"[label]'],
            'an equal run' => ['```js foo``` is inline'],
            'a longer run' => ['```js foo```` x'],
            'a tilde run' => ['~~~js foo ~~~'],
        ];
    }

    #[DataProvider('fallbackProvider')]
    public function testEveryOtherShapeStaysText(string $line): void
    {
        $this->assertStringStartsWith('<p>', (new CarveConverter())->convert("{$line}\npayload\n"));
    }

    public function testAShorterLaterRunDoesNotBlockRecovery(): void
    {
        $this->assertSame(
            "<pre><code class=\"language-js\">payload\n</code></pre>",
            (new CarveConverter())->convert("````js foo``` x\npayload\n````\n"),
        );
    }

    public function testLaterFencesStayInOrder(): void
    {
        $this->assertSame(
            "<pre><code class=\"language-js\">a\n</code></pre>\n<p>text <strong>x</strong></p>\n<pre><code class=\"language-py\">b\n</code></pre>\n<p>after</p>",
            (new CarveConverter())->convert("```js {1,3}\na\n```\n\ntext *x*\n\n```py\nb\n```\n\nafter\n"),
        );
    }

    /**
     * @return array<string, array{0: string, 1: array<array{0: string, 1: int, 2: int, 3: string|null}>}>
     */
    public static function lintProvider(): array
    {
        return [
            'legacy raw html' => ["```raw html\nx\n```\n", [['fence-title-syntax', 1, 1, '```=html']]],
            'legacy raw in a quote' => ["> ```raw latex\n> x\n> ```\n", [['fence-title-syntax', 1, 3, '```=latex']]],
            'legacy raw in a list item' => ["- ```raw html\n  x\n  ```\n", [['fence-title-syntax', 1, 3, '```=html']]],
            'a longer tilde fence keeps its spelling' => ["~~~~raw html\nx\n~~~~\n", [['fence-title-syntax', 1, 1, '~~~~=html']]],
            'a non-format word gets the generic message' => ["```raw 1x\nx\n```\n", [['fence-title-syntax', 1, 1, 'drops invalid opener metadata']]],
            'a word no raw block accepts gets the generic message' => ["```raw _html\nx\n```\n", [['fence-title-syntax', 1, 1, 'drops invalid opener metadata']]],
            'another language' => ["```js {1,3}\nx\n```\n", [['fence-title-syntax', 1, 1, 'drops invalid opener metadata']]],
            'raw alone is a language' => ["```raw\nx\n```\n", []],
            'raw with a title is a language' => ["```raw \"t\"\nx\n```\n", []],
            'a raw block' => ["```=html\nx\n```\n", []],
            'a token that does not recover' => ["```js, x\ncode\n", [['fence-opener-fallback', 1, 1, null]]],
        ];
    }

    /**
     * @param string $source
     * @param array<array{0: string, 1: int, 2: int, 3: string|null}> $expected
     */
    #[DataProvider('lintProvider')]
    public function testLintReportsTheRecovery(string $source, array $expected): void
    {
        $warnings = array_values(array_filter(
            (new SourceLinter())->lint($source),
            static fn ($warning): bool => $warning->rule !== 'unclosed-code-fence',
        ));
        $this->assertSame(
            array_map(static fn (array $row): array => [$row[0], $row[1], $row[2]], $expected),
            array_map(static fn ($warning): array => [$warning->rule, $warning->line, $warning->column], $warnings),
        );
        foreach ($expected as $index => $row) {
            if ($row[3] !== null) {
                $this->assertStringContainsString($row[3], $warnings[$index]->message);
            }
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function lastCharacterProvider(): array
    {
        return [
            'empty' => ['', ''],
            'ascii' => ['ab', 'b'],
            'newline' => ["a\n", "\n"],
            'two bytes' => ['aé', 'é'],
            'three bytes' => ['a€', '€'],
            'four bytes' => ['a😀', '😀'],
            'only a four-byte character' => ['😀', '😀'],
            'a stray continuation byte' => ["a\x80", "\x80"],
            'a truncated sequence' => ["a\xE2\x82", "\x82"],
            'five continuation bytes' => ["\x80\x80\x80\x80\x80", "\x80"],
        ];
    }

    #[DataProvider('lastCharacterProvider')]
    public function testTheBoundaryCharacterComesFromTheEndOfTheText(string $text, string $expected): void
    {
        $method = new ReflectionMethod(CarveRenderer::class, 'lastCharacter');

        $this->assertSame($expected, $method->invoke(null, $text));
    }
}
