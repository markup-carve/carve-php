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
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;
use MarkupCarve\Carve\Generated\Tables;

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

    /**
     * @var int
     */
    private const UNCOUNTED_LEVELS = 8;

    public static function html(string $input, bool $gfm, Sink $sink): string
    {
        $input = self::frontmatter($input, $sink);
        $input = self::protectDefinitionFollowers($input);
        $maxNesting = Tables::BLOCK_DEPTH + self::UNCOUNTED_LEVELS;
        $environment = new Environment([
            // league/commonmark opens a run of containers on one line in quadratic time (8,000 `> - ` took 5 s). A tree
            // past BLOCK_DEPTH containers is refused anyway (C2-A-171); the margin covers the levels that count none
            // (table section, row and cell, paragraph, footnote container).
            'max_nesting_level' => $maxNesting,
            'html_input' => 'allow',
            'allow_unsafe_links' => true,
            ...($gfm ? ['footnote' => ['container_add_hr' => false, 'enable_inline_footnotes' => false]] : []),
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $budget = new InlineBudgetParser($environment, $maxNesting);
        $environment->addBlockStartParser($budget, PHP_INT_MAX);
        // GFM without the tag filter: raw HTML stays raw in the tree, and the render decides what reaches the output.
        if ($gfm) {
            $environment->addExtension(new AutolinkExtension());
            $environment->addExtension(new StrikethroughExtension());
            $environment->addExtension(new BudgetTableExtension($budget));
            $environment->addExtension(new TaskListExtension());
            $environment->addExtension(new FootnoteExtension());
            $environment->addBlockStartParser(new FootnoteStartParser(), self::PRIORITY);
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
     * CommonMark keeps a non-one ordered marker in a paragraph directly after a link definition.
     */
    private static function protectDefinitionFollowers(string $input): string
    {
        $lines = preg_split('/(?<=\n)|(?<=\r)(?!\n)/', $input) ?: [];
        $fence = null;
        foreach ($lines as $i => $line) {
            $body = rtrim($line, "\r\n");
            $visible = preg_replace('/\A(?: {0,3}> ?)+/', '', $body) ?? $body;
            if (preg_match('/^ {0,3}(`{3,}|~{3,})(.*)$/', $visible, $run) === 1) {
                if ($fence === null) {
                    $fence = [$run[1][0], strlen($run[1])];
                } elseif ($run[1][0] === $fence[0] && strlen($run[1]) >= $fence[1] && trim($run[2]) === '') {
                    $fence = null;
                }

                continue;
            }
            if ($fence !== null || !isset($lines[$i + 1])) {
                continue;
            }
            if (preg_match('/\A((?: {0,3}> ?)*)( {0,3})([-+*] |[0-9]{1,9}[.)] )?\[[^\]\r\n]+\]:[^\r\n]*\z/', $body, $definition) !== 1) {
                continue;
            }
            if (preg_match('/\A((?: {0,3}> ?)*)( *)([0-9]{1,9})([.)])(?=[ \t])/', $lines[$i + 1], $marker, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            $quote = $definition[1];
            $indent = $definition[2];
            $list = $definition[3] ?? '';
            $spaces = strlen($marker[2][0]);
            if ($marker[1][0] !== $quote || (int)$marker[3][0] === 1) {
                continue;
            }
            if ($list === '' ? $spaces > 3 : $spaces < strlen($indent . $list)) {
                continue;
            }
            $at = $marker[4][1];
            $lines[$i + 1] = substr_replace($lines[$i + 1], '\\', $at, 0);
        }

        return implode('', $lines);
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
