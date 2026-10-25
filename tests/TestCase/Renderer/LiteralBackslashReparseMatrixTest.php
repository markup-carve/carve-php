<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

final class LiteralBackslashReparseMatrixTest extends TestCase
{
    public function testEveryFollowerInEachInlineHost(): void
    {
        $converter = new CarveConverter();
        $renderer = new CarveRenderer();
        $codec = new AstCodec();
        $followers = array_merge(str_split('!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~'), [' ', "\t", "\n", '', 'a', 'Z', '0', 'é', '中']);
        $hosts = [
            'paragraph' => static fn (string $value): string => "x{$value}y\n",
            'emphasis' => static fn (string $value): string => "/x{$value}y/\n",
            'link' => static fn (string $value): string => "[x{$value}y](url)\n",
            'table' => static fn (string $value): string => "| x{$value}y |\n",
            'heading' => static fn (string $value): string => "# x{$value}y\n",
            'list' => static fn (string $value): string => "- x{$value}y\n",
        ];
        foreach ($hosts as $host => $wrap) {
            foreach ($followers as $follower) {
                foreach (['\\', '\\\\'] as $slashes) {
                    $source = $wrap($slashes . $follower);
                    $written = $renderer->render($converter->parse($source));
                    $label = $host . ' after ' . json_encode($follower) . ' with ' . strlen($slashes) . ' slash(es)';
                    self::assertSame(
                        self::tree($codec->encode($converter->parse($source))),
                        self::tree($codec->encode($converter->parse($written))),
                        $label,
                    );
                    self::assertSame($written, $renderer->render($converter->parse($written)), $label);
                }
            }
        }
    }

    public function testSpaceDoesNotBecomeNonbreakingSpace(): void
    {
        $source = "a\\\\ b\n";
        self::assertSame($source, (new CarveRenderer())->render((new CarveConverter())->parse($source)));
    }

    public function testPaddedTableMarkerNeedsNoEscape(): void
    {
        self::assertSame("| = a |\n", (new CarveRenderer())->render((new CarveConverter())->parse("|\\= a |\n")));
    }

    private static function tree(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            $out = [];
            foreach ($value as $child) {
                $child = self::tree($child);
                $last = array_key_last($out);
                if (
                    $last !== null && is_array($child) && is_array($out[$last])
                    && array_keys($child) === ['type', 'value'] && array_keys($out[$last]) === ['type', 'value']
                    && $child['type'] === 'text' && $out[$last]['type'] === 'text'
                ) {
                    $out[$last]['value'] .= $child['value'];
                } else {
                    $out[] = $child;
                }
            }

            return $out;
        }
        unset($value['pos'], $value['srcByteLength']);
        if (($value['type'] ?? null) === 'escaped_text') {
            $value['type'] = 'text';
        }
        foreach ($value as $key => $child) {
            if ($key !== 'attrs') {
                $value[$key] = self::tree($child);
            }
        }

        return $value;
    }
}
