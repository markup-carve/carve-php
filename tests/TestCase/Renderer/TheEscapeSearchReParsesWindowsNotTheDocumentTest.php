<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use Closure;
use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Converter\HtmlAstBuilder;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The escape search's probes re-parse a window around the relaxed units, so
 * its cost is counted in re-parsed bytes rather than in wall-clock time.
 */
final class TheEscapeSearchReParsesWindowsNotTheDocumentTest extends TestCase
{
    /**
     * Every `* item` paragraph needs its escape, so the halving runs out its budget.
     */
    protected static function paragraphs(int $i): string
    {
        return "<p class=\"n\">* item $i and 1. two</p><p>plain *text* here $i.</p>";
    }

    /**
     * @return iterable<string, array{\Closure(int): string}>
     */
    public static function pages(): iterable
    {
        yield 'paragraphs' => [static fn (int $count): string => implode('', array_map(self::paragraphs(...), range(1, $count)))];
        yield 'definition list descriptions' => [
            static fn (int $count): string => '<dl>' . implode('', array_map(
                static fn (int $i): string => "<dt>term $i</dt><dd>" . self::paragraphs($i) . '</dd>',
                range(1, $count),
            )) . '</dl>',
        ];
    }

    /**
     * @param \Closure(int): string $page
     */
    #[DataProvider('pages')]
    public function testReParsesAFewDocumentsWorthOfSource(Closure $page): void
    {
        $renderer = new class extends CarveRenderer {
            public int $bytes = 0;

            protected function canonicalTree(string $source): ?array
            {
                if ($this->treeCacheSource !== $source) {
                    $this->bytes += strlen($source);
                }

                return parent::canonicalTree($source);
            }
        };

        $output = $renderer->render($this->import($page(300)));

        // Each probe used to re-parse the whole document: about 140-170 documents here.
        self::assertLessThan(20, $renderer->bytes / strlen($output));
    }

    public function testKeepsOnlyTheEscapesEachParagraphNeeds(): void
    {
        $output = (new CarveRenderer())->render($this->import(self::paragraphs(0) . self::paragraphs(1)));

        self::assertSame(
            "{.n}\n\\* item 0 and 1. two\n\nplain \\*text* here 0.\n\n"
            . "{.n}\n\\* item 1 and 1. two\n\nplain \\*text* here 1.\n",
            $output,
        );
    }

    protected function import(string $html): Document
    {
        return (new AstCodec())->decodeImporterTree((new HtmlAstBuilder())->build($html, strlen($html)));
    }
}
