<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `braced-span-opener-as-text` (carve#6538): the closer is a braced closer of the opener's own mark, later in the
 * same leaf block, on any line of it.
 */
final class BracedSpanOpenerAsTextTest extends TestCase
{
    /**
     * The input table and the Expected section of carve#6538.
     *
     * @return array<string, array{0: string, 1: array<string>}>
     */
    public static function ticketRows(): array
    {
        return [
            'closer right after' => ['*a {/b*/}', ['3-5']],
            'closer in the next paragraph' => ["*a {/b* c\n\n*bold {/note*/} more*", ['17-19']],
            'closer on the next line' => ["*a {/b* c\nd /} e", ['3-5']],
            'closer of another mark' => ['*a {/b* c *}', []],
            'bare brace' => ['*a {/b* c }', []],
            'strong opener in emphasis' => ['/a {*b/*}', ['3-5']],
            'quoted next line' => ["> *a {/b*\n> /}", ['5-7']],
            'closer only in the next block' => ["*a {/b* c\n\nd /}", []],
            'closer in a code span' => ["*a {/b* c\nd `/}` e", []],
            'escaped closer' => ["*a {/b* c\nd \\/} e", []],
            'escaped backslash before the closer' => ["*a {/b* c\nd \\\\/} e", ['3-5']],
            'closer after the link' => ["[*a {/b* c](url)\nd /} e", []],
            'second opener of another mark' => ['*a {*b {/c* d /} e*', ['7-9']],
            'two openers with closers' => ['*a {*b {/c* d /} *} e', ['3-5', '7-9']],
            'escaped opener' => ['*a \\{/b* c /}', []],
            'closer inside the link' => ["[*a {/b* c\nd /} e](url)", ['4-6']],
            'strike opener' => ['*a {~b* c~}', ['3-5']],
            'highlight opener' => ['*a {=b* c=}', ['3-5']],
            'insert opener' => ['/a {+b/ c+}', ['3-5']],
            'subscript opener' => ['*a {,b* c,}', ['3-5']],
            'underline opener' => ['*a {_b* c_}', ['3-5']],
            'closer mark read as a bare closer' => ['*x *a {*b* c*}', ['6-8']],
            'closer behind an emphasis mark' => ['*a {/b* /c /}/', ['3-5']],
            'formed span takes the closer' => ['*a {/b* {/c/}', []],
        ];
    }

    /**
     * @param string $source
     * @param array<string> $expected
     *
     * @return void
     */
    #[DataProvider('ticketRows')]
    public function testTicketRow(string $source, array $expected): void
    {
        $this->assertSame($expected, $this->ranges($source));
    }

    /**
     * Outer mark x opener mark x closer placement x container x leading text: 224 variants, the expected finding
     * derived from how the variant was built.
     *
     * @return array<string, array{0: string, 1: array<string>}>
     */
    public static function variants(): array
    {
        $placements = [
            'adjacent' => [static fn (string $o, string $m): string => "{$o}{$m}}", null, true],
            'same line' => [static fn (string $o, string $m): string => "{$o} c {$m}}", null, true],
            'next line' => [static fn (string $o, string $m): string => "{$o} c", static fn (string $m): string => "d {$m}} e", true],
            'next block' => [static fn (string $o, string $m): string => "{$o} c", static fn (string $m): string => "\nd {$m}} e", false],
            'no closer' => [static fn (string $o, string $m): string => "{$o} c", null, false],
            'other mark' => [static fn (string $o, string $m): string => "{$o} c " . ($m === '*' ? '/' : '*') . '}', null, false],
            'bare brace' => [static fn (string $o, string $m): string => "{$o} c }", static fn (string $m): string => 'd } e', false],
        ];
        $containers = [
            'paragraph' => ['', '', true],
            'quote' => ['> ', '> ', true],
            'list item' => ['- ', '  ', true],
            'heading' => ['# ', '', false],
        ];
        $rows = [];
        foreach (['*', '/'] as $outer) {
            foreach (['*', '/'] as $mark) {
                foreach ($placements as $placement => [$first, $second, $found]) {
                    foreach ($containers as $container => [$prefix, $continuation, $multiLine]) {
                        foreach (['', 'x '] as $lead) {
                            $line = $prefix . $lead . $outer . 'a {' . $mark . 'b' . $first($outer, $mark);
                            $source = $line;
                            if ($second !== null) {
                                foreach (explode("\n", $second($mark)) as $next) {
                                    $source .= "\n" . ($next === '' ? rtrim($continuation) : $continuation . $next);
                                }
                            }
                            $at = strlen($prefix . $lead . $outer . 'a ');
                            // `*a {*b**}`: the braced closer right after the bare mark closes the braced span, so it forms.
                            $forms = $placement === 'adjacent' && $outer === $mark;
                            $hit = $found && !$forms && ($second === null || $multiLine);
                            $rows["{$outer} {$mark} {$placement} {$container} " . ($lead === '' ? 'bare' : 'lead')] = [
                                $source,
                                $hit ? [$at . '-' . ($at + 2)] : [],
                            ];
                        }
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * @param string $source
     * @param array<string> $expected
     *
     * @return void
     */
    #[DataProvider('variants')]
    public function testVariant(string $source, array $expected): void
    {
        $this->assertSame($expected, $this->ranges($source), json_encode($source, JSON_UNESCAPED_UNICODE) ?: $source);
    }

    /**
     * @param string $source
     *
     * @return array<string>
     */
    private function ranges(string $source): array
    {
        $out = [];
        foreach (Carve::lint($source) as $diagnostic) {
            if ($diagnostic->code === 'braced-span-opener-as-text') {
                $out[] = $diagnostic->startOffset . '-' . $diagnostic->endOffset;
            }
        }

        return $out;
    }
}
