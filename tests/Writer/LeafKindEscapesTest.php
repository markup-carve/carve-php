<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5934: escapes the first-line test, a caption's pair test and a hard-break block's round place report their
 * kind to the whole leaf, as carve-js writes them.
 */
final class LeafKindEscapesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'definition line after an escaped one' => [" [^1]: n\n[^1]: n\n", "[^1]\\: n\n[^1]\\: n\n"],
            'definition line behind a text line' => [" [^1]: n\nx\n[^1]: n\n", "[^1]\\: n\nx\n[^1]\\: n\n"],
            'every colon of the leaf' => [" [^1]: a:b c: d\nx [a]: y\n", "[^1]\\: a\\:b c\\: d\nx [a]\\: y\n"],
            'one-line definition with later colons' => [" [^1]: n [^2] : x [^3]:\n", "[^1]\\: n [^2] \\: x [^3]\\:\n"],
            'hard-break first line' => ["[^1]\\: n\\\n[^1]: n\n", "[^1]\\: n\\\n[^1]\\: n\n"],
            'caption pair behind a link' => ["| a |\n^ _e_ [l](u) _e_ Fig\n", "| a |\n^ \\_e_ [l](u) \\_e_ Fig\n"],
            'caption pair behind strong' => ["| a |\n^ _e_ *s* _e_ Fig\n", "| a |\n^ \\_e_ *s* \\_e_ Fig\n"],
            'hard-break block line behind a heading line' => [
                "::: \\\n:::\\\n:::\\\n# h\\\n.}\n",
                "::: \\\n\\:::\n\\:::\n\\# h\n\\.}\n:::\n",
            ],
            'hard-break block in a quote' => [
                "> ::: \\\n> :::\\\n> :::\\\n> # h\\\n> .y\n",
                "> ::: \\\n> \\:::\n> \\:::\n> \\# h\n> \\.y\n> :::\n",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testFormat(string $source, string $expected): void
    {
        $result = Carve::formatWithDiagnostics($source);
        self::assertSame($expected, $result->source);
        self::assertSame([], $result->codes());
        self::assertSame($expected, Carve::format($expected));
    }

    /**
     * A plain line of a container the bare read opens is no opener where the local form already reads back.
     */
    public function testPlainLineOfAReopenedContainerStaysBare(): void
    {
        self::assertSame("::: \\\n\\:::\n.y\n\\:::\n.z\n:::\n", Carve::format("::: \\\n:::\\\n.y\\\n:::\\\n.z\n"));
        self::assertSame("::: \\\n- h\n  \\:::\n  {.c}\n:::\n", Carve::format("::: \\\n- h\\\n:::\\\n{.c}\n:::\n"));
    }
}
