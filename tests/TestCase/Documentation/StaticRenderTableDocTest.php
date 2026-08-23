<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Documentation;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\CodeGroupExtension;
use MarkupCarve\Carve\Extension\FencedRenderExtension;
use MarkupCarve\Carve\Extension\ImgFenceExtension;
use MarkupCarve\Carve\Extension\MathBlockExtension;
use MarkupCarve\Carve\Extension\SpoilerExtension;
use MarkupCarve\Carve\Extension\StaticRenderExtensionInterface;
use MarkupCarve\Carve\Extension\TabsExtension;
use MarkupCarve\Carve\Renderer\RenderMode;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Derives the static-render summary table in `docs/extensions.md` instead of
 * trusting it.
 *
 * A TABLE OF EMITTED ELEMENT NAMES IS THE ARTIFACT THAT ROTS SILENTLY, and this
 * one had. It said a static tab panel is headed by `<p class="tabs-label">`
 * while `TabsExtension::renderStaticHtml()` has emitted an `<h3>` since #1541
 * (markup-carve/carve-php#1565), and a second row said `ImgFenceExtension`
 * emits "the sanitized SVG inline" when the default it has always shipped is a
 * sandboxed `<img>` holding a `data:image/svg+xml` URI - inline SVG is the
 * opt-in path, gated behind BOTH the host's `allowInline` and an `{inline}`
 * block attribute. One wrong row means the others were not checked either, and
 * two of the remaining four were merely imprecise rather than right.
 *
 * Static mode is the DEGRADATION path: it is what a consumer gets when the
 * interactive markup cannot be used, so it is read in the docs rather than
 * discovered by experiment. A wrong element name there is a wrong CSS selector
 * for everyone styling it.
 *
 * The two sibling engines reached the same conclusion in the same week:
 * markup-carve/carve-rs#1234 gated its extension list against
 * `registry::keys()` after the docs claimed four extensions where the engine
 * ships 24 modules, and markup-carve/carve-js#1314 required every exported
 * factory to have a section. This is that check for carve-php, in the two
 * halves the table actually makes claims in.
 *
 * WHICH EXTENSIONS: derived from the source tree, so an extension that gains or
 * drops `StaticRenderExtensionInterface` moves the table's required rows with
 * it, in both directions. Nothing is recorded here.
 *
 * WHAT EACH EMITS: the prose is a human sentence and cannot be generated, but
 * the ELEMENT NAMES inside it can be checked. Every HTML start tag the row
 * quotes has to appear - by tag name and by every class it names - in a real
 * static render of that extension. Those renders are the fixtures below, and an
 * extension without one fails rather than being skipped, so a new row cannot
 * arrive unmeasured.
 */
class StaticRenderTableDocTest extends TestCase
{
    /**
     * @var string
     */
    private const HEADING = '### Which extensions carve-php applies `renderStaticHtml` to';

    /**
     * The renders each row is checked against. A row's quoted tags must each
     * appear in at least ONE of its extension's renders: the row summarises
     * everything the extension can emit, not one path through it.
     *
     * @return array<string, array<array{source: string, extension: \MarkupCarve\Carve\Extension\ExtensionInterface, renderers: array<string, \Closure(string): string>}>>
     */
    private function fixtures(): array
    {
        $svg = '<svg viewBox="0 0 1 1"><rect width="1" height="1"/></svg>';

        return [
            'TabsExtension' => [
                [
                    'source' => ":::: tabs\n\n::: tab\n### First\n\nOne.\n:::\n\n::: tab\n### Second\n\nTwo.\n:::\n\n::::\n",
                    'extension' => new TabsExtension(),
                    'renderers' => [],
                ],
            ],
            'CodeGroupExtension' => [
                [
                    'source' => ":::: code-group\n\n``` php [PHP]\necho 1;\n```\n\n``` js [JS]\nconsole.log(1);\n```\n\n::::\n",
                    'extension' => new CodeGroupExtension(),
                    'renderers' => [],
                ],
            ],
            'MathBlockExtension' => [
                [
                    'source' => "``` math\nE = mc^2\n```\n",
                    'extension' => new MathBlockExtension(),
                    'renderers' => ['math' => fn (string $tex, bool $display = false): string => '<math>x</math>'],
                ],
                [
                    'source' => "``` math\nE = mc^2\n```\n",
                    'extension' => new MathBlockExtension(),
                    'renderers' => [],
                ],
            ],
            'FencedRenderExtension' => [
                [
                    'source' => "``` mermaid\ngraph TD; A-->B;\n```\n",
                    'extension' => new FencedRenderExtension('mermaid'),
                    'renderers' => ['mermaid' => fn (string $source): string => '<svg>x</svg>'],
                ],
                [
                    'source' => "``` mermaid\ngraph TD; A-->B;\n```\n",
                    'extension' => new FencedRenderExtension('mermaid'),
                    'renderers' => [],
                ],
            ],
            'ImgFenceExtension' => [
                [
                    'source' => "```img\n" . $svg . "\n```\n",
                    'extension' => new ImgFenceExtension(),
                    'renderers' => [],
                ],
                [
                    'source' => "{inline}\n```img\n" . $svg . "\n```\n",
                    'extension' => new ImgFenceExtension(allowInline: true),
                    'renderers' => [],
                ],
                [
                    'source' => "```img\nnot an svg at all\n```\n",
                    'extension' => new ImgFenceExtension(),
                    'renderers' => [],
                ],
            ],
            'SpoilerExtension' => [
                [
                    'source' => "::: spoiler \"Ending\" [Season finale]\nEveryone lives.\n:::\n",
                    'extension' => new SpoilerExtension(),
                    'renderers' => [],
                ],
                [
                    'source' => "Plot: :spoiler[the butler did it].\n",
                    'extension' => new SpoilerExtension(),
                    'renderers' => [],
                ],
            ],
        ];
    }

    /**
     * The table, as `extension short name => the cell describing its output`.
     *
     * @return array<string, string>
     */
    private function documentedRows(): array
    {
        $page = file_get_contents(dirname(__DIR__, 3) . '/docs/extensions.md');
        $this->assertIsString($page, 'docs/extensions.md is unreadable, so the table cannot be checked.');

        $offset = strpos($page, self::HEADING);
        $this->assertNotFalse(
            $offset,
            'docs/extensions.md no longer carries the heading "' . self::HEADING . '". The table this '
            . 'test derives lives under it; if the section was renamed, rename it here too rather than '
            . 'letting the check quietly measure nothing.',
        );

        $rows = [];
        foreach (explode("\n", substr($page, $offset)) as $line) {
            if ($rows !== [] && !str_starts_with($line, '|')) {
                break;
            }
            if (!preg_match('/^\| `([A-Za-z]+Extension)`[^|]*\|(.*)\|\s*$/', $line, $match)) {
                continue;
            }
            $rows[$match[1]] = $match[2];
        }

        $this->assertNotEmpty(
            $rows,
            'no rows were read out of the static-render table. An empty read makes every assertion '
            . 'below vacuous, so it fails here instead of reporting a clean run.',
        );

        return $rows;
    }

    /**
     * Every class under src/ that implements the static-render hook.
     *
     * @return array<string> Short class names.
     */
    private function staticRenderExtensions(): array
    {
        $root = dirname(__DIR__, 3);
        $found = [];
        $directory = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));
        foreach ($directory as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root . '/src/'), -4);
            $class = 'MarkupCarve\\Carve\\' . str_replace('/', '\\', $relative);
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->implementsInterface(StaticRenderExtensionInterface::class)) {
                continue;
            }
            $found[] = $reflection->getShortName();
        }
        sort($found);

        $this->assertNotEmpty(
            $found,
            'no class under src/ implements StaticRenderExtensionInterface. That is a scan failure, '
            . 'not an empty extension set, and an empty set would make the comparison below pass '
            . 'against any table at all.',
        );

        return $found;
    }

    public function testTheTableNamesExactlyTheExtensionsThatImplementTheHook(): void
    {
        $documented = array_keys($this->documentedRows());
        sort($documented);

        $this->assertSame(
            $this->staticRenderExtensions(),
            $documented,
            'the static-render table in docs/extensions.md does not name the same extensions as the '
            . 'source tree. An extension that gains renderStaticHtml() needs a row; one that loses it '
            . 'has to lose its row, or the page keeps describing a path that no longer exists. '
            . 'DetailsExtension is correctly absent: it reaches the same end without implementing the '
            . 'interface, and the paragraph under the table says so.',
        );
    }

    public function testEveryRowHasAFixtureToBeMeasuredAgainst(): void
    {
        $documented = array_keys($this->documentedRows());
        sort($documented);
        $fixtures = array_keys($this->fixtures());
        sort($fixtures);

        // A row with no fixture would be SKIPPED by the assertion below rather
        // than failing, which is how a table row goes unmeasured while the suite
        // stays green.
        $this->assertSame($fixtures, $documented, 'every row in the static-render table needs a render to be checked against.');
    }

    public function testEveryElementTheTableNamesIsActuallyEmitted(): void
    {
        $fixtures = $this->fixtures();
        $findings = [];
        $checked = 0;

        foreach ($this->documentedRows() as $extension => $cell) {
            $renders = [];
            foreach ($fixtures[$extension] as $fixture) {
                $converter = new CarveConverter(mode: RenderMode::STATIC, renderers: $fixture['renderers']);
                $converter->addExtension($fixture['extension']);
                $renders[] = $converter->convert($fixture['source']);
            }

            $tags = $this->startTagsIn($cell);

            // A row that names no element is the OTHER way this table rots, and
            // it is the way it hid the ImgFence defect: "the sanitized SVG
            // inline, else the source" describes output without naming what
            // carries it, so nothing above could measure it and the reader still
            // came away with the wrong element. Prose is welcome; prose INSTEAD
            // of the element is not.
            if ($tags === []) {
                $findings[] = sprintf(
                    'the %s row names no HTML element, so nothing in it can be measured against a '
                    . 'render. Say which element the extension emits.',
                    $extension,
                );

                continue;
            }

            foreach ($tags as $tag) {
                $checked++;
                foreach ($renders as $html) {
                    if ($this->emits($html, $tag['name'], $tag['classes'])) {
                        continue 2;
                    }
                }

                $findings[] = sprintf(
                    "docs/extensions.md says %s emits `%s`, and no static render of it does.\nThe renders were:\n%s",
                    $extension,
                    $tag['literal'],
                    implode("\n", $renders),
                );
            }
        }

        $this->assertSame([], $findings, "the static-render table does not describe what the renderers emit:\n" . implode("\n\n", $findings));

        $this->assertGreaterThan(
            0,
            $checked,
            'the table named no HTML start tag at all, so nothing was measured. The rows describe '
            . 'emitted elements; a table that stopped naming them is a rewritten table, not a passing check.',
        );
    }

    /**
     * The HTML start tags a table cell quotes, read out of its inline code spans.
     *
     * @return array<array{literal: string, name: string, classes: array<string>}>
     */
    private function startTagsIn(string $cell): array
    {
        $tags = [];
        preg_match_all('/`([^`]+)`/', $cell, $spans);
        foreach ($spans[1] as $span) {
            preg_match_all('/<([a-z][a-z0-9]*)((?:\s[^<>]*)?)>/i', $span, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $classes = [];
                if (preg_match('/\bclass="([^"]*)"/', $match[2], $classAttr) === 1) {
                    $classes = preg_split('/\s+/', trim($classAttr[1])) ?: [];
                }
                $tags[] = [
                    'literal' => $match[0],
                    'name' => strtolower($match[1]),
                    'classes' => array_values(array_filter($classes)),
                ];
            }
        }

        return $tags;
    }

    /**
     * Does the html carry a start tag of this name holding every one of these
     * classes? Matched on name plus classes rather than on the literal string:
     * the engine legitimately adds attributes the table does not enumerate
     * (`role`, `aria-label`), and a row is a claim about the element, not about
     * its full attribute list.
     *
     * @param string $html
     * @param string $name
     * @param array<string> $classes
     */
    private function emits(string $html, string $name, array $classes): bool
    {
        preg_match_all('/<' . preg_quote($name, '/') . '(\s[^<>]*)?>/i', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $attributes = $match[1] ?? '';
            $present = [];
            if (preg_match('/\bclass="([^"]*)"/', $attributes, $classAttr) === 1) {
                $present = preg_split('/\s+/', trim($classAttr[1])) ?: [];
            }
            if (array_diff($classes, $present) === []) {
                return true;
            }
        }

        return false;
    }
}
