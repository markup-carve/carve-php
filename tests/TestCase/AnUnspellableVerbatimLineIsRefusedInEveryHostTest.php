<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P11-078: a code value line that opens a block in its host has no spelling, so the writer refuses it
 * instead of writing a document that reads back without the line.
 */
class AnUnspellableVerbatimLineIsRefusedInEveryHostTest extends TestCase
{
    /**
     * First-line prefix and the lines before and after the paragraph, as in the spec fixture test.
     *
     * @var array<string, array{string, string, string}>
     */
    protected const HOSTS = [
        'paragraph' => ['', '', ''],
        'quote' => ['> ', '', ''],
        'bullet' => ['- ', '', ''],
        'quotedItem' => ['> - ', '', ''],
        'ordered' => ['1. ', '', ''],
        'description' => [': ', ":: t\n", ''],
        'footnote' => ['[^n]: ', "y[^n]\n\n", ''],
        'div' => ['', "::: d\n", "\n:::"],
        'quotedFootnote' => ['[^n]: > ', "y[^n]\n\n", ''],
    ];

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedRows(): array
    {
        $fixture = json_decode((string)file_get_contents(__DIR__ . '/../spec/tests/fixtures/verbatim-opener-line-fmt.json'), true);
        $rows = [];
        foreach ($fixture['rows'] as $row) {
            if (!isset($row['source']) && isset(self::HOSTS[$row['host']])) {
                $rows[$row['id']] = [$row['host'], $row['value']];
            }
        }

        return $rows;
    }

    #[DataProvider('refusedRows')]
    public function testTheFixtureRowIsRefused(string $host, string $value): void
    {
        $this->expectException(SourceUnspellableException::class);
        CarveConverter::carve()->renderWithReport($this->documentWithCode($host, $value));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unspellableValues(): array
    {
        $rows = [];
        foreach (["a\n> b", "a\n# h", "a\n[^d]: u", "a\n%% c", "a\n:: t", "a\n*[H]: x", "a\n>\nc", "a\n[d]: u\nc", "a\n[d]:\nc", "a\n:::\nc", "a\n```\nc", "a\n{.c}\nc", "a\n| x |\nc", "a\n***\nc", "a\n-\nc", "a\n> "] as $value) {
            foreach (array_keys(self::HOSTS) as $host) {
                $rows[$host . ': ' . json_encode($value)] = [$host, $value];
            }
        }
        foreach (["a\n- b", "a\n1. b", "a\n. b", "a\n* * *"] as $value) {
            foreach (['bullet', 'quotedItem', 'ordered', 'description', 'footnote'] as $host) {
                $rows[$host . ': ' . json_encode($value)] = [$host, $value];
            }
        }

        return $rows;
    }

    #[DataProvider('unspellableValues')]
    public function testALineThatOpensABlockInItsHostIsRefused(string $host, string $value): void
    {
        $this->expectException(SourceUnspellableException::class);
        CarveConverter::carve()->renderWithReport($this->documentWithCode($host, $value));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function spellableValues(): array
    {
        $rows = [];
        foreach (["a\nb\nc", "a\n[d]: u", "a\n[d]:", "a\n:::", "a\n```", "a\n{.c}", "a\n| x |", "a\n***", "a\n>", "a\n>b", "a\n#", "a\n#h", "a\n::t", "a\n[^d]:", "a\n*[H]:", "a\n_ _ _\nc", "a\n{}\nc", "a\n+ b"] as $value) {
            foreach (array_keys(self::HOSTS) as $host) {
                $rows[$host . ': ' . json_encode($value)] = [$host, $value];
            }
        }
        foreach (["a\n- b", "a\n1. b", "a\n*"] as $value) {
            foreach (['paragraph', 'quote', 'div', 'quotedFootnote'] as $host) {
                $rows[$host . ': ' . json_encode($value)] = [$host, $value];
            }
        }

        return $rows;
    }

    #[DataProvider('spellableValues')]
    public function testASpellableValueReadsBack(string $host, string $value): void
    {
        $writer = CarveConverter::carve();
        $result = $writer->renderWithReport($this->documentWithCode($host, $value));

        $this->assertSame([], $result->losses);
        $this->assertContains($value, self::codeValues($writer->parse($result->value)), $result->value);
    }

    private function documentWithCode(string $host, string $value): Node
    {
        [$first, $before, $after] = self::HOSTS[$host];
        $document = CarveConverter::carve()->parse("{$before}{$first}`x`{$after}\n");
        $stack = [$document];
        while ($stack !== []) {
            $node = array_pop($stack);
            if ($node instanceof Code) {
                $node->setContent($value);
            } elseif (method_exists($node, 'getChildren')) {
                array_push($stack, ...$node->getChildren());
            }
        }

        return $document;
    }

    /**
     * @return array<string>
     */
    private static function codeValues(Node $node): array
    {
        $values = [];
        foreach (method_exists($node, 'getChildren') ? $node->getChildren() : [] as $child) {
            if ($child instanceof Code) {
                $values[] = $child->getContent();
            }
            array_push($values, ...self::codeValues($child));
        }

        return $values;
    }
}
