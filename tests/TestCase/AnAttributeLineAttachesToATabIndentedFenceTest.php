<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class AnAttributeLineAttachesToATabIndentedFenceTest extends TestCase
{
    use RuleATodo;

    /**
     * @throws \UnexpectedValueException
     *
     * @return iterable<string, array{string, string}>
     */
    public static function examples(): iterable
    {
        $rows = json_decode(
            (string)file_get_contents(__DIR__ . '/../fixtures/fence_admission_regressions.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if (!is_array($rows)) {
            throw new UnexpectedValueException('Fence fixtures must be an array.');
        }
        foreach ($rows as $row) {
            if (
                !is_array($row) || !is_string($row['id'] ?? null)
                || !is_string($row['source'] ?? null) || !is_string($row['html'] ?? null)
            ) {
                throw new UnexpectedValueException('Fence fixture is invalid.');
            }
            if (in_array($row['id'], ['preservation-194', 'preservation-2005', 'preservation-9813', 'preservation-14270'], true)) {
                yield $row['id'] => [$row['source'], $row['html']];
            }
        }

        yield 'g003' => ["- a\n\t::: note\n   {.c}\n\t\t```\nx\n```\nafter\n", '<ul><li>a<aside class="admonition note" aria-label="Note"><pre class="c"><code></code></pre></aside></li></ul><p>x<code>after</code></p>'];
        yield 'g004' => ["- a\n\t::: tip\n  {.c}\n      ```\nx\n```\nafter\n", '<ul><li>a<aside class="admonition tip" aria-label="Tip"><pre class="c"><code></code></pre></aside></li></ul><p>x<code>after</code></p>'];
        yield 'g015' => ["1. a\n \t::: box\n   {.c}\n\t\t```\nx\n```\nafter\n", '<ol><li>a<div class="box"><pre class="c"><code></code></pre></div></li></ol><p>x<code>after</code></p>'];
        yield 'g019' => ["1. a\n  :::\n  \t{.c}\n  \t\t```\nafter\n", '<ol><li>a</li></ol><div><pre class="c"><code>after </code></pre></div>'];
        yield 'g021' => ["- a\n\t::: tip\n  \t{.c}\n\t\t```\nx\n```\nafter\n", '<ul><li>a<aside class="admonition tip" aria-label="Tip"><pre class="c"><code></code></pre></aside></li></ul><p>x<code>after</code></p>'];
        yield 'g033' => ["1. a\n  :::\n   {.c}\n\t\t```\n```\n", '<ol><li>a</li></ol><div><pre class="c"><code></code></pre></div>'];
        yield 'g043' => ["- a\n  \t::: box\n   {.c}\n\t ```\nx\n```\nafter\n", '<ul><li>a<div class="box"><pre class="c"><code></code></pre></div></li></ul><p>x<code>after</code></p>'];
        yield 'g050' => ["1. a\n  :::\n\t{.c}\n  \t\t```\nx\n```\nafter\n", '<ol><li>a</li></ol><div><pre class="c"><code>x </code></pre><p>after</p></div>'];
        yield 'g086' => ["1. a\n  :::\n   {.c}\n\t ```\nx\n```\nafter\n", '<ol><li>a</li></ol><div><pre class="c"><code>x </code></pre><p>after</p></div>'];
        yield 'g089' => ["1. a\n\t::: box\n   \t{.c}\n  ```\nafter\n", '<ol><li>a<div class="box"></div></li></ol><p><code>after</code></p>'];
        yield 'g125' => ["1. a\n  :::\n  \t{.c}\n\t\t```\n```\n", '<ol><li>a</li></ol><div><pre class="c"><code></code></pre></div>'];
        yield 'g132' => ["1. a\n\t::: note\n   \t{.c}\n  ```\nx\n```\nafter\n", '<ol><li>a<aside class="admonition note" aria-label="Note"></aside></li></ol><p><code>x</code>after</p>'];
        yield 'g172' => ["1. a\n\t::: box\n   \t{.c}\n  ```\n```\n", '<ol><li>a<div class="box"></div></li></ol><p><code></code></p>'];

        yield 'space-indented-control' => [
            "- a\n  ::: note\n   {.c}\n    ```\n",
            '<ul><li>a<aside class="admonition note" aria-label="Note"><pre class="c"><code></code></pre></aside></li></ul>',
        ];
    }

    #[DataProvider('examples')]
    public function testAttributeAttachesToFence(string $source, string $expected): void
    {
        $html = (new CarveConverter())->convert($source);
        self::assertSame(
            preg_replace('/\s+/', '', $expected),
            preg_replace('/\s+/', '', $html),
        );
    }
}
