<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
use League\CommonMark\Extension\Footnote\Node\Footnote;
use League\CommonMark\Extension\Footnote\Node\FootnoteBackref;
use League\CommonMark\Extension\Footnote\Node\FootnoteContainer;
use League\CommonMark\Extension\Footnote\Node\FootnoteRef;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Markdown (CommonMark 0.31) and GFM to HTML with `league/commonmark`, with the hub's carriers for raw HTML, math,
 * container directives and frontmatter.
 */
final class MarkdownHtml
{
    /**
     * @var int
     */
    private const PRIORITY = 100;

    public static function html(string $input, bool $gfm, Sink $sink): string
    {
        $input = self::frontmatter($input, $sink);
        $environment = new Environment([
            'html_input' => 'allow',
            'allow_unsafe_links' => true,
            'footnote' => ['container_add_hr' => false, 'enable_inline_footnotes' => false],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        // GFM without the tag filter: raw HTML stays raw in the tree, and the render decides what reaches the output.
        if ($gfm) {
            $environment->addExtension(new AutolinkExtension());
            $environment->addExtension(new StrikethroughExtension());
            $environment->addExtension(new TableExtension());
            $environment->addExtension(new TaskListExtension());
            $environment->addExtension(new FootnoteExtension());
            $footnotes = new FootnoteRenderer();
            foreach ([FootnoteRef::class, FootnoteBackref::class, Footnote::class, FootnoteContainer::class] as $class) {
                $environment->addRenderer($class, $footnotes, self::PRIORITY);
            }
        }
        $raw = new RawHtmlRenderer($sink);
        $environment->addRenderer(HtmlBlock::class, $raw, self::PRIORITY);
        $environment->addRenderer(HtmlInline::class, $raw, self::PRIORITY);
        $environment->addInlineParser(new MathInlineParser(), self::PRIORITY);
        $environment->addBlockStartParser(new MathBlockParser(), self::PRIORITY);
        $environment->addBlockStartParser(new DirectiveParser(), self::PRIORITY);
        $math = new MathRenderer();
        $environment->addRenderer(Math::class, $math);
        $environment->addRenderer(MathBlock::class, $math);
        $directives = new DirectiveRenderer();
        $environment->addRenderer(Directive::class, $directives);
        $environment->addRenderer(DirectiveLabel::class, $directives);

        return (string)(new MarkdownConverter($environment))->convert($input);
    }

    /**
     * YAML (`---`) or TOML (`+++`) frontmatter as `micromark-extension-frontmatter` reads it: fenced at the very start.
     * Its text is not HTML; the hub adds it to the tree after the import.
     */
    private static function frontmatter(string $input, Sink $sink): string
    {
        if (preg_match('/\A(---|\+\+\+)[ \t]*(?:\r\n|\r|\n)/', $input, $open) !== 1) {
            return $input;
        }
        $fence = preg_quote($open[1], '/');
        $start = strlen($open[0]);
        if (preg_match('/(?:\A|\r\n|\r|\n)' . $fence . '[ \t]*(?:\r\n|\r|\n|\z)/', $input, $close, PREG_OFFSET_CAPTURE, $start - 1) !== 1) {
            return $input;
        }
        $at = (int)$close[0][1];
        $lines = $at < $start ? [] : (preg_split('/\r\n|\r|\n/', substr($input, $start, $at - $start)) ?: []);
        $sink->frontmatterFormat = $open[1] === '---' ? 'yaml' : 'toml';
        $sink->frontmatter = $lines === [] ? '' : implode("\n", $lines) . "\n";

        return substr($input, $at + strlen($close[0][0]));
    }
}
