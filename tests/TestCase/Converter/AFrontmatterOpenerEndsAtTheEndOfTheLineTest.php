<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An opener is `---`, an optional space, an optional format token, then the end
 * of the line (PART 1 `frontmatter_open`). A form feed, vertical tab or no-break
 * space after the token makes the line content, so the canonical opener the
 * importer writes must not turn it into front matter.
 */
class AFrontmatterOpenerEndsAtTheEndOfTheLineTest extends TestCase
{
    /**
     * @var string
     */
    private const BODY = "\na: 1\n\n---\n\nt\n";

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function contentOpeners(): array
    {
        return [
            'form feed' => ["---yaml\f", "\\-\\-\\-yaml\f", "---yaml\f"],
            'form feed then space' => ["---yaml\f ", "\\-\\-\\-yaml\f ", "---yaml\f"],
            'vertical tab' => ["---yaml\x0B", "\\-\\-\\-yaml\x0B", "---yaml\x0B"],
            'no-break space' => ["---yaml\u{A0}", "\\-\\-\\-yaml\u{A0}", '---yaml&nbsp;'],
        ];
    }

    #[DataProvider('contentOpeners')]
    public function testATrailingNonBlankCharacterKeepsTheLineAsContent(string $opener, string $written, string $rendered): void
    {
        $carve = (new MarkdownToCarve())->convert($opener . self::BODY);

        $this->assertSame($written . "\na: 1\n\n---\n\nt\n", $carve);
        $this->assertSame(
            '<p>' . $rendered . "\na: 1</p>\n<hr>\n<p>t</p>\n",
            CarveConverter::create()->convert($carve),
        );
    }

    public function testTheFormFeedCaseRendersLikeTheSpecCorpus(): void
    {
        $corpus = dirname(__DIR__, 2) . '/spec/tests/corpus/487-a-form-feed-or-a-no-break-space-is-content-wherever-whitespace-is-tested-9.html';
        $carve = (new MarkdownToCarve())->convert("---yaml\f" . self::BODY);

        $this->assertSame((string)file_get_contents($corpus), CarveConverter::create()->convert($carve));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function openers(): array
    {
        return [
            'typed' => ['---yaml'],
            'typed after one space' => ['--- yaml'],
            'bare' => ['---'],
            'bare with trailing space and tab' => ["--- \t"],
            'typed with trailing space and tab' => ["---yaml \t"],
        ];
    }

    #[DataProvider('openers')]
    public function testAnOpenerFollowedOnlyBySpacesOrTabsStillOpens(string $opener): void
    {
        $this->assertSame(
            "---yaml\na: 1\n\n---\n\nt\n",
            (new MarkdownToCarve())->convert($opener . self::BODY),
        );
    }
}
