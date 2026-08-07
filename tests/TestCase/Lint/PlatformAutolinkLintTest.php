<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\MarkdownHabitLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PlatformAutolinkLintTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: list<array{0: string, 1: int, 2: int, 3: string}>}>
     */
    public static function batteryProvider(): array
    {
        $mention = MarkdownHabitLinter::RULE_PLATFORM_MENTION_TOKEN;
        $issue = MarkdownHabitLinter::RULE_PLATFORM_ISSUE_REFERENCE;

        return [
            'email prose' => ["Write to user@example.com today.\n", []],
            'types package' => ["Install @types/node now.\n", [[$mention, 1, 9, '@types']]],
            'param annotation' => ["The @param annotation.\n", [[$mention, 1, 5, '@param']]],
            'daily shortcut' => ["The @daily shortcut.\n", [[$mention, 1, 5, '@daily']]],
            'issue number' => ["See #42 now.\n", [[$issue, 1, 5, '#42']]],
            'issue in parens' => ["See (#123) now.\n", [[$issue, 1, 6, '#123']]],
            'selector' => ["The #a1 selector.\n", []],
            'release tag' => ["The #release-1.0 tag.\n", []],
            'markdown heading' => ["## 2 things\n", []],
            'inline code is scanned' => [
                "Use `@param` and `#42` here.\n",
                [[$mention, 1, 6, '@param'], [$issue, 1, 19, '#42']],
            ],
            'plain code fence' => ["```\n@param and #42\n```\n", []],
            'raw code fence' => ["```=html\n@param and #42\n```\n", []],
            'frontmatter' => ["---\nauthor: @mark\nissue: #42\n---\n\nBody.\n", []],
            'link reference definition' => ["[a]: https://e.com/@mark/#42\n\nSee [a].\n", []],
            'abbreviation definition' => ["*[HTML]: @mark and #42\n\nHTML here.\n", []],
            'link destination' => ["See [x](https://e.com/@mark/#42) now.\n", []],
            'bare url' => ["See https://e.com/#99 now.\n", []],
            'link label' => ["See [@param](https://e.com) now.\n", [[$mention, 1, 6, '@param']]],
            'bare close-paren text' => ["See ](#123) now.\n", [[$issue, 1, 7, '#123']]],
            'line comment' => ["%% @param and #42\n\nBody.\n", []],
            'both tokens' => [
                "Both @param and #42 here.\n",
                [[$mention, 1, 6, '@param'], [$issue, 1, 17, '#42']],
            ],
            'double at' => ["Email a@@b now.\n", []],
            'path segment' => ["Path a/#42 here.\n", []],
            'double hash' => ["Tag ##42 here.\n", []],
            'dotted mention' => ["The @a.b.c name.\n", [[$mention, 1, 5, '@a.b.c']]],
            'mention before period' => ["End with @mark.\n", [[$mention, 1, 10, '@mark']]],
            'issue with suffix' => ["See #42-x now.\n", []],
            'underscore mention' => ["The @_x tag.\n", [[$mention, 1, 5, '@_x']]],
            'digit mention' => ["The @1x tag.\n", [[$mention, 1, 5, '@1x']]],
            'unreferenced footnote' => ["Body.\n\n[^n]: About @param and #42\n", []],
            'referenced footnote' => [
                "Body[^n] text.\n\n[^n]: About @param and #42\n",
                [[$mention, 3, 13, '@param'], [$issue, 3, 24, '#42']],
            ],
            'caption before fence' => [
                "^ Caption with @param and #42\n\n```\ncode\n```\n",
                [[$mention, 1, 16, '@param'], [$issue, 1, 27, '#42']],
            ],
            'caption after fence' => [
                "```\ncode\n```\n\n^ Caption with @param and #42\n",
                [[$mention, 5, 16, '@param'], [$issue, 5, 27, '#42']],
            ],
            'quoted fence' => ["> ```\n> @param and #42\n> ```\n", []],
            'list fence' => ["- ```\n  @param and #42\n  ```\n", []],
            'tilde fence' => ["~~~\n@param and #42\n~~~\n", []],
            'heading content' => [
                "# About @param and #42\n",
                [[$mention, 1, 9, '@param'], [$issue, 1, 20, '#42']],
            ],
            'table cells' => [
                "| a | b |\n|---|---|\n| @param | #42 |\n",
                [[$mention, 3, 3, '@param'], [$issue, 3, 12, '#42']],
            ],
            'email autolink' => ["Mail <mark@example.com> now.\n", []],
            'url autolink' => ["See <https://e.com/#99> now.\n", []],
            'escaped mention' => ["Use \\@param here.\n", [[$mention, 1, 6, '@param']]],
            'escaped issue' => ["Use \\#42 here.\n", [[$issue, 1, 6, '#42']]],
            'unreferenced footnote continuation' => [
                "Body.\n\n[^n]: First @param line\n    second #42 line\n",
                [],
            ],
            'referenced footnote continuation' => [
                "Body[^n].\n\n[^n]: First @param line\n    second #42 line\n",
                [[$mention, 3, 13, '@param'], [$issue, 4, 12, '#42']],
            ],
            'different referenced footnote' => ["Body[^m].\n\n[^n]: About @param\n\n[^m]: ok\n", []],
            'indented link reference definition' => ["  [a]: https://e.com/@mark\n\nSee [a].\n", []],
            'comment fence' => ["%%%\n@param and #42\n%%%\n\nBody.\n", []],
            'after frontmatter' => ["---\ntitle: x\n---\n\nSee @param now.\n", [[$mention, 5, 5, '@param']]],
            'late thematic break' => ["Body.\n\n---\nauthor: @mark\n---\n", [[$mention, 4, 9, '@mark']]],
            'unclosed fence' => ["```\n@param and #42\n", []],
            'fence with language' => ["``` php\n@param\n```\n", []],
            'div is scanned' => ["::: note\n@param and #42\n:::\n", [[$mention, 2, 1, '@param'], [$issue, 2, 12, '#42']]],
            'mention starts' => ["@param starts.\n", [[$mention, 1, 1, '@param']]],
            'issue starts' => ["#42 starts.\n", [[$issue, 1, 1, '#42']]],
            // These six discriminate the two masks from the patterns and from
            // each other. Most URL and destination rows are ALSO silenced by
            // the lookbehind - a token after a `/` is out on the pattern alone
            // - so removing either mask left every one of them passing. Here
            // the token follows `=` or `&`, which no pattern excludes, so only
            // the mask can silence it. Verified against carve-js, which reports
            // nothing on all six.
            'bare URL query holds an at-word' => ["See https://e.com/x?u=@mark now.\n", []],
            'bare URL query holds a hash-number' => ["See https://e.com/x?a=1&b=#42 now.\n", []],
            'bare URL path holds an at-word after a colon' => ["See https://e.com/x:@mark now.\n", []],
            'link destination query holds an at-word' => ["See [x](https://e.com/y?u=@mark) now.\n", []],
            // RELATIVE, so the bare-URL mask cannot reach it and only the
            // destination walk can.
            'relative destination holds an at-word' => ["See [x](/y?u=@mark) now.\n", []],
            'relative destination holds a hash-number' => ["See [x](/y?a=1&b=#42) now.\n", []],
        ];
    }

    #[DataProvider('batteryProvider')]
    public function testBattery(string $source, array $expected): void
    {
        $this->assertSame($expected, $this->summarize($source, ['platforms' => ['github']]));
    }

    /**
     * The half nothing else catches.
     *
     * A rule that fires under BOTH default and opt-in options satisfies the
     * battery above and every other check here while being a default-off
     * regression, so this has to read the rule under DEFAULT options and assert
     * silence. Rows that report nothing even under opt-in are asserted to be
     * exactly that rather than waved through: without it a row proves silence
     * it was never in a position to break, which is a check that cannot fail.
     *
     * @param string $source
     * @param list<array{0: string, 1: int, 2: int, 3: string}> $expected
     */
    #[DataProvider('batteryProvider')]
    public function testPlatformRulesAreDefaultOff(string $source, array $expected): void
    {
        if ($expected === []) {
            $this->assertSame([], $this->summarize($source, ['platforms' => ['github']]));

            return;
        }

        // This document DOES trigger, so silence below is a fact about the
        // options rather than about the document.
        $this->assertNotSame([], $this->summarize($source, ['platforms' => ['github']]));
        $this->assertNoPlatformRules((new MarkdownHabitLinter())->lint($source));
        $this->assertNoPlatformRules((new MarkdownHabitLinter())->lint($source, ['platforms' => []]));
    }

    /**
     * Ignored rather than refused, and that asymmetry with the CLI is the
     * ruling: an API caller has a type checker, while a misspelt flag that
     * silently reported nothing would look exactly like a clean document.
     */
    public function testAnUnknownPlatformNameIsIgnoredOnTheApi(): void
    {
        $this->assertSame([], $this->summarize("@param and #42\n", ['platforms' => ['gitlab']]));
        $this->assertSame(
            [
                [MarkdownHabitLinter::RULE_PLATFORM_MENTION_TOKEN, 1, 1, '@param'],
                [MarkdownHabitLinter::RULE_PLATFORM_ISSUE_REFERENCE, 1, 12, '#42'],
            ],
            $this->summarize("@param and #42\n", ['platforms' => ['gitlab', 'github']]),
        );
    }

    /**
     * A host named twice is one host. Reporting each token twice would be a
     * caller-visible duplicate from a list that says nothing new.
     */
    public function testADuplicatedPlatformNameReportsEachTokenOnce(): void
    {
        $this->assertSame(
            [[MarkdownHabitLinter::RULE_PLATFORM_MENTION_TOKEN, 1, 1, '@param']],
            $this->summarize("@param\n", ['platforms' => ['github', 'github']]),
        );
    }

    /**
     * A non-list value is not a selection. It has to be ignored rather than
     * fatal: the option arrives from a config file or a JSON body in every
     * caller that is not writing PHP by hand.
     */
    public function testANonListPlatformsValueSelectsNothing(): void
    {
        $this->assertSame([], $this->summarize("@param and #42\n", ['platforms' => 'github']));
        $this->assertSame([], $this->summarize("@param and #42\n", ['platforms' => null]));
    }

    /**
     * The platform pass is additive. The Markdown-habit rules this class
     * shipped with report the same findings whether or not a platform is named,
     * or the new option would have changed a rule it has nothing to do with.
     */
    public function testTheMarkdownHabitRulesAreUnaffectedByThePlatformOption(): void
    {
        $source = "Use **bold** with @param here.\n";
        $habits = static fn (array $warnings): array => array_values(array_filter(
            array_map(static fn ($warning): string => $warning->rule, $warnings),
            static fn (string $rule): bool => !str_starts_with($rule, 'platform-'),
        ));

        $this->assertSame(
            $habits((new MarkdownHabitLinter())->lint($source)),
            $habits((new MarkdownHabitLinter())->lint($source, ['platforms' => ['github']])),
        );
        $this->assertSame(
            [MarkdownHabitLinter::RULE_STRONG_ASTERISKS],
            $habits((new MarkdownHabitLinter())->lint($source, ['platforms' => ['github']])),
        );
    }

    public function testMentionRuleIsReachableByName(): void
    {
        $this->assertSame(
            [[MarkdownHabitLinter::RULE_PLATFORM_MENTION_TOKEN, 1, 1, '@param']],
            $this->summarize("@param\n", ['platforms' => ['github']]),
        );
    }

    public function testIssueRuleIsReachableByName(): void
    {
        $this->assertSame(
            [[MarkdownHabitLinter::RULE_PLATFORM_ISSUE_REFERENCE, 1, 1, '#42']],
            $this->summarize("#42\n", ['platforms' => ['github']]),
        );
    }

    /**
     * A TYPED frontmatter opener is the canonical spelling, so reading only the
     * bare `---` reported every token in a typed metadata block - text the
     * renderer never puts in the body. Found by codex review and confirmed
     * against carve-js, which reports nothing on both of these.
     *
     * @param string $source
     */
    #[DataProvider('typedFrontmatterProvider')]
    public function testATypedFrontmatterBlockIsNeverRead(string $source): void
    {
        $this->assertSame([], $this->summarize($source, ['platforms' => ['github']]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function typedFrontmatterProvider(): array
    {
        return [
            'typed' => ["---yaml\nauthor: @mark\nissue: #42\n---\n\nBody.\n"],
            'typed with a space' => ["--- toml\nauthor = \"@mark\"\n---\n\nBody.\n"],
            'bare' => ["---\nauthor: @mark\n---\n\nBody.\n"],
        ];
    }

    /**
     * The block still has to END, or a typed opener would swallow the document
     * and both rules would go quiet everywhere - which the test above cannot
     * catch, since it only ever asserts silence.
     */
    public function testTheBodyAfterATypedFrontmatterBlockIsStillRead(): void
    {
        $this->assertSame(
            [[MarkdownHabitLinter::RULE_PLATFORM_MENTION_TOKEN, 5, 5, '@param']],
            $this->summarize("---yaml\ntitle: x\n---\n\nSee @param now.\n", ['platforms' => ['github']]),
        );
    }

    /**
     * Two readings deliberately NOT changed, both measured against carve-js and
     * identical there, so changing either would make this engine the odd one
     * out on a point the clause does not settle. Raised by codex review and
     * dismissed with a reason; pinned here so the dismissal is deliberate
     * rather than forgotten.
     *
     * The destination mask is line-based, so `[x](#42)` inside a code span is
     * masked like any other destination even though the rules do read code
     * spans. And an abbreviation definition carrying a container prefix is NOT
     * treated as a definition line, so its expansion is read.
     *
     * @param string $source
     * @param list<array{0: string, 1: int, 2: int, 3: string}> $expected
     */
    #[DataProvider('matchesTheReferenceEngineProvider')]
    public function testReadingsThatMatchTheReferenceEngine(string $source, array $expected): void
    {
        $this->assertSame($expected, $this->summarize($source, ['platforms' => ['github']]));
    }

    /**
     * @return array<string, array{string, list<array{0: string, 1: int, 2: int, 3: string}>}>
     */
    public static function matchesTheReferenceEngineProvider(): array
    {
        return [
            'a destination inside a code span is masked' => ["Use `[x](#42)` here.\n", []],
            'an abbreviation definition in a blockquote is read' => [
                "> *[HTML]: @mark\n",
                [[MarkdownHabitLinter::RULE_PLATFORM_MENTION_TOKEN, 1, 12, '@mark']],
            ],
            'an abbreviation definition at column 0 is not' => ["*[HTML]: @mark\n", []],
        ];
    }

    public function testKnownPlatformsComeFromTheRuleTable(): void
    {
        $this->assertSame(['github'], MarkdownHabitLinter::knownPlatforms());
    }

    /**
     * @return list<array{0: string, 1: int, 2: int, 3: string}>
     */
    private function summarize(string $source, array $options): array
    {
        return array_map(
            static fn ($warning): array => [
                $warning->rule,
                $warning->line,
                $warning->column,
                substr($source, $warning->start, $warning->end - $warning->start),
            ],
            (new MarkdownHabitLinter())->lint($source, $options),
        );
    }

    /**
     * @param list<\MarkupCarve\Carve\Lint\LintWarning> $warnings
     */
    private function assertNoPlatformRules(array $warnings): void
    {
        $rules = array_map(static fn ($warning): string => $warning->rule, $warnings);

        $this->assertNotContains(MarkdownHabitLinter::RULE_PLATFORM_MENTION_TOKEN, $rules);
        $this->assertNotContains(MarkdownHabitLinter::RULE_PLATFORM_ISSUE_REFERENCE, $rules);
    }
}
