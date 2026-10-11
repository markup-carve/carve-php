<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use Generator;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

class ShortInlineWordTest extends TestCase
{
    public function testNestedWordsKeepTheirSourcePositions(): void
    {
        foreach ([1, 64, 65] as $length) {
            $word = str_repeat('a', $length);
            foreach (['*WORD*', '{*WORD*}', '> {*outer {/WORD/}*}', "前\r\n\r\n- {*WORD*}"] as $pattern) {
                $source = str_replace('WORD', $word, $pattern);
                $converter = new CarveConverter(parser: new BlockParser(trackPositions: true));
                $found = false;
                foreach ($this->descendants($converter->parse($source)) as $node) {
                    if ($node instanceof Text && $node->getContent() === $word) {
                        $span = $node->getPos();
                        $this->assertNotNull($span);
                        $this->assertSame($word, mb_substr($source, $span->startOffset, $span->endOffset - $span->startOffset, 'UTF-8'));
                        $before = mb_substr($source, 0, $span->startOffset, 'UTF-8');
                        $line = substr_count($before, "\n") + 1;
                        $linePrefix = substr($before, (strrpos($before, "\n") ?: -1) + 1);
                        $column = mb_strlen($linePrefix, 'UTF-8') + 1;
                        $this->assertSame($line, $span->startLine);
                        $this->assertSame($line, $span->endLine);
                        $this->assertSame($column, $span->startColumn);
                        $this->assertSame($column + $length, $span->endColumn);
                        $found = true;
                    }
                }
                $this->assertTrue($found, $source);
            }
        }
    }

    public function testLetterAndUnknownMatcherTriggersStillRun(): void
    {
        foreach (['a', null] as $trigger) {
            $converter = new CarveConverter();
            $converter->getParser()->getInlineParser()->addInlineMatcher(
                static function (string $text, int $pos): ?array {
                    return substr($text, $pos, 5) === 'alpha'
                        ? ['node' => new Text('matched'), 'end' => $pos + 5] : null;
                },
                triggerChars: $trigger,
            );
            $this->assertSame('<p><strong>matched</strong> <strong>matched</strong></p>', trim($converter->convert('*alpha* {*alpha*}')));
        }
    }

    public function testConditionalLetterMatcherUsesSpanContent(): void
    {
        $converter = new CarveConverter();
        $converter->getParser()->getInlineParser()->addInlinePattern(
            '/alpha/',
            static fn (): Text => new Text('matched'),
            requiredSubstring: 'alpha',
        );
        $this->assertSame('<p><strong>matched</strong> <strong>beta</strong></p>', trim($converter->convert('{*alpha*} {*beta*}')));
    }

    public function testAbbreviationsStillExpandInsideSpans(): void
    {
        $converter = new CarveConverter();
        $html = $converter->convert("*ABC* {*ABC*}\n\n*[ABC]: expansion\n");
        $this->assertSame(2, substr_count($html, '<abbr'));
        $this->assertSame(2, substr_count($html, 'title="expansion"'));
    }

    private function descendants(Node $node): Generator
    {
        yield $node;

        foreach ($node->getChildren() as $child) {
            yield from $this->descendants($child);
        }
    }
}
