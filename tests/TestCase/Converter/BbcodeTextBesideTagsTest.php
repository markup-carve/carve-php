<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\BbcodeToCarve;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\SmartPunctuation;
use MarkupCarve\Carve\Node\Inline\Strike;
use MarkupCarve\Carve\Node\Inline\Strong;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Inline\Underline;
use MarkupCarve\Carve\Node\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Text beside a converted formatting tag stays text. The same cases, and the
 * same generated corpus, are pinned in carve-js.
 */
class BbcodeTextBesideTagsTest extends TestCase
{
    private BbcodeToCarve $bbcode;

    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->bbcode = new BbcodeToCarve();
        $this->converter = new CarveConverter();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function caseProvider(): array
    {
        return [
            'strikethrough partner' => ['q ~}[s]_ a}[/s] q', '<p>q ~}<s>_ a}</s> q</p>'],
            'hashtag before a tag' => ['q #[u]x[/u] q', '<p>q #<u>x</u> q</p>'],
            'hashtag across a stray tag' => ['q #[/i]x q', '<p>q #x q</p>'],
            'mention across a stray tag' => ['q @[/b]x q', '<p>q @x q</p>'],
            'span after an unclosed tag' => ['q [B]{} q', '<p>q [B]{} q</p>'],
            'highlight in plain text' => ['q =x== q', '<p>q =x== q</p>'],
            'numeric character reference' => ['q a &#8212; b q', '<p>q a &amp;#8212; b q</p>'],
            'unresolved reference' => ['q [u][*x*[u]*** q', '<p>q [u][*x*[u]*** q</p>'],
            'stray close tag inside a tag' => ['q [i][/u][/i] q', '<p>q  q</p>'],
        ];
    }

    /**
     * @param string $bbcode
     * @param string $html
     */
    #[DataProvider('caseProvider')]
    public function testTheTextStaysText(string $bbcode, string $html): void
    {
        $this->assertSame($html, trim($this->converter->convert($this->bbcode->convert($bbcode))));
    }

    public function testAGeneratedPostRendersExactlyAsItsTagsSay(): void
    {
        $atoms = [
            '[b]', '[/b]', '[i]', '[/i]', '[u]', '[/u]', '[s]', '[/s]', 'x', 'ab',
            ' ', '_', '*', '/', '~', '=',
            '{', '}', '#', '@', ':', '\\', '`', '$', '^', '+',
            '-', '<', '>', '[', ']', '(', ')', '!', '%', '|',
        ];
        $seed = 1;
        $wrong = [];
        for ($t = 0; $t < 3000; $t++) {
            $post = 'q ';
            $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
            $n = 1 + $seed % 12;
            for ($k = 0; $k < $n; $k++) {
                $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
                $post .= $atoms[$seed % count($atoms)];
            }
            $post .= ' q';
            if ($this->imported($post) !== $this->reference($post)) {
                $wrong[] = $post;
            }
        }

        $this->assertSame([], $wrong);
    }

    /**
     * The import's tree, written the way reference() writes its answer. Smart
     * typography applies to any Carve source and reads as the characters it was
     * written with; any other construct shows up as itself and fails.
     */
    private function imported(string $post): string
    {
        $out = '';
        foreach ($this->converter->parse($this->bbcode->convert($post))->getChildren() as $block) {
            $out .= $block instanceof Paragraph ? '<p>' . $this->write($block->getChildren()) . '</p>' : '<?block>';
        }

        return $out;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     */
    private function write(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= match (true) {
                $node instanceof Text, $node instanceof EscapedText, $node instanceof SmartPunctuation => htmlspecialchars($node->getContent(), ENT_COMPAT),
                $node instanceof Strong => '<strong>' . $this->write($node->getChildren()) . '</strong>',
                $node instanceof Emphasis => '<em>' . $this->write($node->getChildren()) . '</em>',
                $node instanceof Underline => '<u>' . $this->write($node->getChildren()) . '</u>',
                $node instanceof Strike => '<s>' . $this->write($node->getChildren()) . '</s>',
                default => '<?' . $this->typeOf($node) . '>',
            };
        }

        return $out;
    }

    private function typeOf(Node $node): string
    {
        return substr((string)strrchr($node::class, '\\'), 1);
    }

    /**
     * An independent reading of the four formatting tags, straight to HTML: the
     * innermost close must match, an unclosed tag is literal, a stray close tag
     * goes, a tag inside its own kind adds nothing, and an empty one goes.
     */
    private function reference(string $post): string
    {
        $root = ['kind' => '', 'open' => '', 'kids' => [], 'unclosed' => false];
        $nodes = [&$root];
        $from = 0;
        preg_match_all('/\[(\/?)(b|i|u|s)\]/i', $post, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $m) {
            $top = &$nodes[count($nodes) - 1];
            $top['kids'][] = substr($post, $from, $m[0][1] - $from);
            $from = $m[0][1] + strlen($m[0][0]);
            $kind = strtolower($m[2][0]);
            if ($m[1][0] === '') {
                $top['kids'][] = ['kind' => $kind, 'open' => $m[0][0], 'kids' => [], 'unclosed' => false];
                $nodes[] = &$top['kids'][count($top['kids']) - 1];
            } elseif ($top['kind'] === $kind) {
                array_pop($nodes);
            }
            unset($top);
        }
        $nodes[count($nodes) - 1]['kids'][] = substr($post, $from);
        for ($k = count($nodes) - 1; $k >= 1; $k--) {
            $nodes[$k]['unclosed'] = true;
        }
        unset($nodes);

        return '<p>' . $this->referenceKids($root['kids'], []) . '</p>';
    }

    /**
     * @param array<int, mixed> $kids
     * @param array<string, true> $open
     */
    private function referenceKids(array $kids, array $open): string
    {
        $tags = ['b' => 'strong', 'i' => 'em', 'u' => 'u', 's' => 's'];
        $out = '';
        foreach ($kids as $kid) {
            if (is_string($kid)) {
                $out .= htmlspecialchars($kid, ENT_COMPAT);
            } elseif ($kid['unclosed']) {
                $out .= htmlspecialchars($kid['open'], ENT_COMPAT) . $this->referenceKids($kid['kids'], $open);
            } elseif (isset($open[$kid['kind']])) {
                $out .= $this->referenceKids($kid['kids'], $open);
            } else {
                $inner = $this->referenceKids($kid['kids'], $open + [$kid['kind'] => true]);
                $out .= $inner === '' ? '' : '<' . $tags[$kid['kind']] . '>' . $inner . '</' . $tags[$kid['kind']] . '>';
            }
        }

        return $out;
    }
}
