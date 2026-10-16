<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class BlockFamilyRoundFourTest extends TestCase
{
    public function testGeneratedCases(): void
    {
        $json = <<<'JSON'
[
  {"id":"r4_035","source":":: t\n: 1. para\n      - li\n```\n","expected":"<dl>\n<dt>t</dt>\n<dd>\n<ol>\n<li>para\n<ul>\n<li>li\n<code></code></li>\n</ul>\n</li>\n</ol>\n</dd>\n</dl>"},
  {"id":"r4_036","source":":: t\n: 1. para\n         - li\n```\n","expected":"<dl>\n<dt>t</dt>\n<dd>\n<ol>\n<li>para\n<ul>\n<li>li\n<code></code></li>\n</ul>\n</li>\n</ol>\n</dd>\n</dl>"},
  {"id":"r4_045","source":":: t\n: - para\n     - li\n```\n","expected":"<dl>\n<dt>t</dt>\n<dd>\n<ul>\n<li>para\n<ul>\n<li>li\n<code></code></li>\n</ul>\n</li>\n</ul>\n</dd>\n</dl>"}
]
JSON;
        $cases = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $converter = new CarveConverter();
        foreach ($cases as $case) {
            self::assertSame(
                preg_replace('/\s+/', '', $case['expected']),
                preg_replace('/\s+/', '', $converter->convert($case['source'])),
                $case['id'],
            );
        }
    }
}
