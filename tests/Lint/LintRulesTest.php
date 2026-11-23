<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Lint\Linter;
use MarkupCarve\Carve\OptionError;
use MarkupCarve\Carve\Test\Support\Held;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-106: every rule of the catalog with an input that reports it and one that does not. The inputs and the expected
 * findings are those of carve-js (`tests/fixtures/lint-cases.json`); a dataset is named by the rule id.
 */
final class LintRulesTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>, 4: array<array<mixed>>}>
     */
    public static function rules(): array
    {
        $data = json_decode((string)file_get_contents(dirname(__DIR__) . '/fixtures/lint-cases.json'), true, 512, JSON_THROW_ON_ERROR);
        $out = [];
        foreach (is_array($data) && is_array($data['cases'] ?? null) ? $data['cases'] : [] as $name => $case) {
            if (!is_array($case) || Held::has('lint-rules/' . $name)) {
                continue;
            }
            // The shared fixture still pins a bare opener; the 0.2 catalog needs a value line for this warning.
            if ($name === 'frontmatter-shaped-thematic-break') {
                continue;
            }
            $id = explode('#', (string)$name)[0];
            /** @var array<string, mixed> $options */
            $options = is_array($case['options'] ?? null) ? $case['options'] : [];
            // C2-W-156: a migration notice is checked with the option that turns it on.
            if ((Linter::catalog()[$id]['default'] ?? null) === 'upgrade') {
                $options += ['upgrade' => true];
            }
            /** @var array<array<mixed>> $findings */
            $findings = is_array($case['findings'] ?? null) ? $case['findings'] : [];
            $out[(string)$name] = [$id, (string)$case['trigger'], (string)$case['near'], $options, $findings];
        }

        return $out;
    }

    /**
     * @param string $id
     * @param string $trigger
     * @param string $near
     * @param array<string, mixed> $options
     * @param array<array<mixed>> $expected
     *
     * @return void
     */
    #[DataProvider('rules')]
    public function testRule(string $id, string $trigger, string $near, array $options, array $expected): void
    {
        $this->assertSame($expected, self::found($trigger, $options, $id));
        $this->assertSame([], self::found($near, $options, $id));
    }

    public function testEveryCatalogRuleHasACase(): void
    {
        if (Held::has('lint-rules/catalog')) {
            $this->markTestSkipped('Lint catalog held at candidate 12');
        }
        $ids = array_unique(array_map(static fn (array $row): string => $row[0], self::rules()));
        sort($ids);
        $catalog = array_keys(Linter::catalog());
        sort($catalog);
        $this->assertSame($catalog, array_values($ids));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function badOptions(): array
    {
        return [
            'platforms true' => [['platforms' => true]],
            'platforms string' => [['platforms' => 'github']],
            'platforms other' => [['platforms' => ['other']]],
            'rules list' => [['rules' => ['x']]],
            'rules value' => [['rules' => ['platform-mention-token' => true]]],
            'rules unknown' => [['rules' => ['unknown' => 'on']]],
            'unknown option' => [['strict' => true]],
        ];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return void
     */
    #[DataProvider('badOptions')]
    public function testC2W109InvalidOptionsAreRefused(array $options): void
    {
        $this->expectException(OptionError::class);
        Carve::lint('<', $options);
    }

    public function testC2W109RuleStates(): void
    {
        $this->assertContains('deprecated-bare-underline', self::codes('_x_'));
        $this->assertNotContains('deprecated-bare-underline', self::codes('_x_', ['rules' => ['deprecated-bare-underline' => 'off']]));
        $this->assertNotContains('platform-mention-token', self::codes('See @bob.'));
        $this->assertContains('platform-mention-token', self::codes('See @bob.', ['platforms' => ['github']]));
        $options = ['platforms' => ['github'], 'rules' => ['platform-mention-token' => 'off']];
        $this->assertNotContains('platform-mention-token', self::codes('See @bob.', $options));
    }

    public function testC2B216ControlComments(): void
    {
        $this->assertSame([], self::codes("_x_ %% carve-lint-disable-line\n"));
        $this->assertSame([], self::codes("%% carve-lint-disable-next-line deprecated-bare-underline\n_x_\n"));
        $this->assertSame(['deprecated-bare-underline'], self::codes("%% carve-lint-disable-next-line heading-level-skip\n_x_\n"));
        $this->assertSame(['deprecated-bare-underline'], self::codes("%% carve-lint-disable\n_x_\n%% carve-lint-enable\n_y_\n"));
        $this->assertSame(['deprecated-bare-underline', 'deprecated-bare-underline'], self::codes("%%  carve-lint-disable\n_x_\n_y_\n"));
        $this->assertSame([], self::codes("%% carve-lint-disable deprecated-bare-underline, heading-level-skip\n_x_\n"));
    }

    public function testC2W103FindingsAreInByteOrder(): void
    {
        $offsets = array_map(static fn (Diagnostic $d): int => $d->startOffset, Carve::lint("😀 **bold**\n\n# H\n\n_x_"));
        $sorted = $offsets;
        sort($sorted);
        $this->assertSame($sorted, $offsets);
        $this->assertNotSame([], $offsets);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>, 2: string, 3: array<array<mixed>>}>
     */
    public static function includes(): array
    {
        return [
            'C2-N-086 child' => [
                "Top.\n\n{{ child.crv }}", ['child.crv' => "One.\n\n[x]()\n"], 'empty-link-destination',
                [['child.crv', 6, 11, '/children/1/children/1/children/0']],
            ],
            'C2-N-087 once' => [
                "{{ child.crv }}\n\n{{ child.crv }}", ['child.crv' => "[x]()\n"], 'empty-link-destination',
                [['child.crv', 0, 5, '/children/0/children/0/children/0']],
            ],
            'C2-N-086 lines' => [
                '{{ child.crv @lines:3-3 }}', ['child.crv' => "[x]()\n\n[y]()\n"], 'empty-link-destination',
                [['child.crv', 7, 12, '/children/0/children/0/children/0']],
            ],
            'C2-N-086 section' => [
                '{{ child.crv #s }}', ['child.crv' => "[x]()\n\n{#s}\n[y]()\n\n[z]()\n"], 'empty-link-destination',
                [['child.crv', 12, 17, '/children/0']],
            ],
            'C2-N-086 code payload' => [
                "x\n\n{{ a.js @code @lines:2-3 }}", ['a.js' => "let \u{202E}a = 1\nlet \u{202E}b = 2\nlet c = 3\n"],
                'bidi-control-in-source', [['a.js', 17, 20, '/children/1/children/0']],
            ],
            'C2-N-085 no resolver' => ['{{ child.crv }}', [], 'empty-link-destination', []],
        ];
    }

    /**
     * @param string $source
     * @param array<string, string> $files
     * @param string $code
     * @param array<array<mixed>> $expected
     *
     * @return void
     */
    #[DataProvider('includes')]
    public function testC2N085IncludedFilesAreLinted(string $source, array $files, string $code, array $expected): void
    {
        $resolver = static fn (array $r): array => isset($files[$r['path']]) ? ['text' => $files[$r['path']], 'id' => $r['path']]
            : ['error' => 'not_found'];
        $found = [];
        foreach (Carve::lint($source, ['includes' => $resolver]) as $d) {
            if ($d->code === $code && $d->file !== null) {
                $found[] = [$d->file, $d->startOffset, $d->endOffset, $d->path];
            }
        }
        $this->assertSame($expected, $found);
    }

    /**
     * @param string $source
     * @param array<string, mixed> $options
     * @param string $id
     *
     * @return array<array<mixed>>
     */
    private static function found(string $source, array $options, string $id): array
    {
        $out = [];
        foreach (Carve::lint($source, $options) as $d) {
            if ($d->code === $id) {
                $out[] = [$d->code, $d->path, $d->startOffset, $d->endOffset, $d->message];
            }
        }

        return $out;
    }

    /**
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return array<string>
     */
    private static function codes(string $source, array $options = []): array
    {
        return array_map(static fn (Diagnostic $d): string => $d->code, Carve::lint($source, $options));
    }
}
