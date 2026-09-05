<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AnUnfinishedFenceInADescriptionBodyOwnsItsBodyTest extends TestCase
{
    #[DataProvider('caseProvider')]
    public function testRequiredOutput(string $src, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($src), "\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function caseProvider(): array
    {
        return [
            'dd-```-closed' => [":: t\n: - ``` x\ncode\n```\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\">code\n```\n</code></pre>\n      </li>\n    </ul>\n  </dd>\n</dl>"],
            'dd-```-unterminated' => [":: t\n: - ``` x\ncode\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\">code\n</code></pre>\n      </li>\n    </ul>\n  </dd>\n</dl>"],
            'dd-~~~-closed' => [":: t\n: - ~~~ x\ncode\n~~~\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\">code\n~~~\n</code></pre>\n      </li>\n    </ul>\n  </dd>\n</dl>"],
            'dd-~~~-unterminated' => [":: t\n: - ~~~ x\ncode\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\">code\n</code></pre>\n      </li>\n    </ul>\n  </dd>\n</dl>"],
            'dd-infoless' => [":: t\n: - ```\ncode\n```\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <pre><code>code\n```\n</code></pre>\n      </li>\n    </ul>\n  </dd>\n</dl>"],
            'ctrl-item-host' => ["- - ``` x\ncode\n```\n", "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\">code\n```\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>"],
            'ctrl-fn-host' => ["[^f]: b\n\n  - ``` x\n  code\n  ```\n\nx[^f]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>b</p>\n      <ul>\n        <li>\n          <pre><code class=\"language-x\">\n</code></pre>\n        </li>\n      </ul>\n      <p>code\n<code></code><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'corpus/455-2' => [":: t\n: - ~~~ x\ncode\n~~~\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\">code\n~~~\n</code></pre>\n      </li>\n    </ul>\n  </dd>\n</dl>"],
            'corpus/455-3' => [":: t\n: - ```\ncode\n```\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <pre><code>code\n```\n</code></pre>\n      </li>\n    </ul>\n  </dd>\n</dl>"],
            'corpus/455-4' => [":: t\n: - ``` x\ncode\n\n:: t2\n: plain\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\">code\n</code></pre>\n      </li>\n    </ul>\n  </dd>\n  <dt>t2</dt>\n  <dd>plain</dd>\n</dl>"],
            'corpus/455' => [":: t\n: - ``` x\ncode\n```\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\">code\n```\n</code></pre>\n      </li>\n    </ul>\n  </dd>\n</dl>"],
            'dd-body-lead-not-a-fence-is-unframed' => [":: t\n: - plain\ncont\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>plain\ncont</li>\n    </ul>\n  </dd>\n</dl>"],
        ];
    }
}
