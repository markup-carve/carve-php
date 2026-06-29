<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Parser\MatcherContext;
use PHPUnit\Framework\TestCase;

class InlineMatcherTest extends TestCase
{
    public function testInlineMatcherCanEmitCustomNode(): void
    {
        $converter = new CarveConverter();
        $inline = $converter->getParser()->getInlineParser();

        $inline->addInlineMatcher(function (string $text, int $pos, MatcherContext $ctx): ?array {
            if (!preg_match('/\G@([a-z]+)/', $text, $matches, 0, $pos)) {
                return null;
            }

            return ['node' => new Text('USER:' . $matches[1]), 'end' => $pos + strlen($matches[0])];
        });

        $this->assertSame('<p>Hello USER:bob</p>', trim($converter->convert('Hello @bob')));
    }

    public function testInlineMatcherCarriesParsedChildren(): void
    {
        $converter = new CarveConverter();
        $inline = $converter->getParser()->getInlineParser();

        $inline->addInlineMatcher(function (string $text, int $pos, MatcherContext $ctx): ?array {
            if (!preg_match('/\G\{\{([^}]+)\}\}/', $text, $matches, 0, $pos)) {
                return null;
            }

            $span = new Span();
            $span->setAttribute('class', 'template');
            foreach ($ctx->parseInlines($matches[1]) as $child) {
                $span->appendChild($child);
            }

            return ['node' => $span, 'end' => $pos + strlen($matches[0])];
        });

        $this->assertSame('<p><span class="template"><strong>name</strong></span></p>', trim($converter->convert('{{*name*}}')));
    }

    public function testInlineMatcherIsCoreFirst(): void
    {
        $converter = new CarveConverter();
        $inline = $converter->getParser()->getInlineParser();

        $inline->addInlineMatcher(function (string $text, int $pos, MatcherContext $ctx): ?array {
            if (!preg_match('/\G\*([^*]+)\*/', $text, $matches, 0, $pos)) {
                return null;
            }

            return ['node' => new Text('HIJACKED'), 'end' => $pos + strlen($matches[0])];
        });

        $this->assertSame('<p><strong>important</strong></p>', trim($converter->convert('*important*')));
    }

    public function testAddInlinePatternStillWorks(): void
    {
        $converter = new CarveConverter();
        $converter->getParser()->getInlineParser()->addInlinePattern(
            '/@([a-z]+)/',
            fn (string $match, array $groups): Text => new Text('USER:' . $groups[1]),
        );

        $this->assertSame('<p>USER:bob</p>', trim($converter->convert('@bob')));
    }

    public function testAddInlinePatternIsNowCoreFirst(): void
    {
        $converter = new CarveConverter();
        $converter->getParser()->getInlineParser()->addInlinePattern(
            '/\[([^\]]+)\]/',
            fn (string $match, array $groups): Text => new Text('HIJACKED'),
        );

        $this->assertSame('<p><a href="/u">t</a></p>', trim($converter->convert('[t](/u)')));
    }

    public function testMatcherPriorityAndRegistrationOrder(): void
    {
        $converter = new CarveConverter();
        $inline = $converter->getParser()->getInlineParser();

        $inline->addInlineMatcher(
            function (string $text, int $pos, MatcherContext $ctx): ?array {
                if (($text[$pos] ?? null) !== '%') {
                    return null;
                }

                return ['node' => new Text('LOW'), 'end' => $pos + 1];
            },
            priority: 1,
        );

        $inline->addInlineMatcher(
            function (string $text, int $pos, MatcherContext $ctx): ?array {
                if (($text[$pos] ?? null) !== '%') {
                    return null;
                }

                return ['node' => new Text('HIGH'), 'end' => $pos + 1];
            },
            priority: 5,
        );

        $this->assertSame('<p>HIGH</p>', trim($converter->convert('%')));
    }
}
