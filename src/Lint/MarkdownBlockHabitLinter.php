<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;

/**
 * Reports three Markdown block habits that render differently in Carve with no error (carve#4814):
 * a setext underline, a closing `#` run on a heading, and four-space indented code.
 */
final class MarkdownBlockHabitLinter
{
    /**
     * @var string
     */
    public const RULE_SETEXT = 'setext-heading-underline';

    /**
     * @var string
     */
    public const RULE_CLOSING_HASHES = 'heading-closing-hashes';

    /**
     * @var string
     */
    public const RULE_INDENTED_CODE = 'indented-code-block';

    /**
     * @var string
     */
    private const SETEXT_UNDERLINE = '/^((?:[ \t]*>)*[ \t]*)(-{3,}|={3,})[ \t\r]*$/';

    /**
     * @var string
     */
    private const HEADING_CLOSING_HASHES = '/^(.*\S[ \t]+)(#+)[ \t\r]*$/';

    /**
     * @var string
     */
    private const SETEXT_MESSAGE = 'Carve has no underline heading: this run is not a heading underline. Write the heading with a leading "#" instead.';

    /**
     * @var list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    private array $warnings = [];

    /**
     * @var array<int, string>
     */
    private array $lines = [];

    /**
     * @var array<int, int>
     */
    private array $lineStarts = [];

    /**
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source): array
    {
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();
        $document = $converter->parse($source);
        $this->warnings = [];
        $this->lines = [];
        $this->lineStarts = [];
        $offset = 0;
        foreach (explode("\n", $source) as $index => $line) {
            $this->lines[$index] = $line;
            $this->lineStarts[$index] = $offset;
            $offset += strlen($line) + 1;
        }

        $this->visit($document);
        $this->lintIndentedCode($document->getChildren());

        return $this->warnings;
    }

    private function visit(Node $node): void
    {
        $children = $node->getChildren();
        if ($node instanceof Heading) {
            $this->lintClosingHashes($node, $children);
        }
        $pos = $node->getPos();
        if ($node->getType() === 'paragraph' && $pos !== null) {
            for ($ln = $pos->startLine + 1; $ln <= $pos->endLine; $ln++) {
                if (preg_match(self::SETEXT_UNDERLINE, $this->lines[$ln - 1] ?? '', $m) === 1 && $m[2][0] === '=') {
                    $this->push($ln, strlen($m[1]), strlen($m[2]), self::RULE_SETEXT, self::SETEXT_MESSAGE);
                }
            }
        }
        foreach ($children as $i => $child) {
            $prev = $children[$i - 1] ?? null;
            $ln = $child->getPos()?->startLine;
            if ($prev !== null && $ln !== null && $prev->getType() === 'paragraph' && $child->getType() === 'thematic_break' && $prev->getPos()?->endLine === $ln - 1) {
                if (preg_match(self::SETEXT_UNDERLINE, $this->lines[$ln - 1] ?? '', $m) === 1 && $m[2][0] === '-') {
                    $this->push($ln, strlen($m[1]), strlen($m[2]), self::RULE_SETEXT, self::SETEXT_MESSAGE);
                }
            }
            $this->visit($child);
        }
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\Heading $heading
     * @param array<\MarkupCarve\Carve\Node\Node> $children
     */
    private function lintClosingHashes(Heading $heading, array $children): void
    {
        $pos = $heading->getPos();
        $last = $children === [] ? null : $children[array_key_last($children)];
        if ($pos === null || !$last instanceof Text || preg_match('/(\S?)[ \t]+#+$/', $last->getContent(), $run) !== 1) {
            return;
        }
        // A heading of only hashes (`## ##`) has no text for the run to close.
        if ($run[1] === '' && count($children) < 2) {
            return;
        }
        $ln = $pos->endLine;
        if (preg_match(self::HEADING_CLOSING_HASHES, $this->lines[$ln - 1] ?? '', $m) !== 1) {
            return;
        }
        $this->push($ln, strlen($m[1]), strlen($m[2]), self::RULE_CLOSING_HASHES, sprintf('The closing "%s" run is heading text in Carve, not a closing marker. Delete it.', $m[2]));
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $top
     */
    private function lintIndentedCode(array $top): void
    {
        $top = array_values($top);
        foreach ($top as $i => $node) {
            $ln = $node->getPos()?->startLine;
            if ($node->getType() !== 'paragraph' || $ln === null || $ln < 2 || $this->followsList($top, $i)) {
                continue;
            }
            if (trim($this->lines[$ln - 2] ?? '') !== '') {
                continue;
            }
            $line = $this->lines[$ln - 1] ?? '';
            if (preg_match('/^ {4,}(?=\S)/', $line, $indent) === 1) {
                $width = strlen($indent[0]);
                $this->push($ln, $width, strlen($line) - $width, self::RULE_INDENTED_CODE, 'Four-space indentation does not make a code block in Carve; this renders as a paragraph. Use a ``` fence.');
            }
        }
    }

    /**
     * A paragraph written flush under a list, with no blank line, still reads as that list's text.
     *
     * @param list<\MarkupCarve\Carve\Node\Node> $top
     * @param int $i
     */
    private function followsList(array $top, int $i): bool
    {
        $j = $i - 1;
        while (
            isset($top[$j]) && $top[$j]->getType() === 'paragraph'
            && $top[$j]->getPos()?->startLine === ($j > 0 ? ($top[$j - 1]->getPos()->endLine ?? -2) : -2) + 1
        ) {
            $j--;
        }

        return isset($top[$j]) && $top[$j]->getType() === 'list';
    }

    private function push(int $line, int $at, int $size, string $rule, string $message): void
    {
        $text = $this->lines[$line - 1] ?? '';
        $start = ($this->lineStarts[$line - 1] ?? 0) + $at;
        $this->warnings[] = new LintWarning($line, mb_strlen(substr($text, 0, $at), 'UTF-8') + 1, $rule, $message, $start, $start + $size);
    }
}
