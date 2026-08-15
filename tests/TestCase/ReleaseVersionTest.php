<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * The version this library reports is the version that shipped.
 *
 * `LIB_VERSION` is read by people who cannot see the build: `carve --version`
 * prints it, `carve fmt --stamp` writes it into a document as
 * `generated-by: carve-php <version>`, and an embedder quotes it in a bug
 * report. When it names a release that is not the one running, every conclusion
 * drawn from it is wrong and the reader has no way to notice - they suspect
 * their own build first. carve-js shipped `LIB_VERSION = '0.1.0'` through three
 * releases while its package was at 0.1.3, found by an outside embedder
 * (markup-carve/carve-js#1074).
 *
 * There was a check here already, in CliTest: the changelog had to CONTAIN a
 * `## [LIB_VERSION] - ` heading. It cannot fail in the direction that matters.
 * A release that cuts `## [0.1.5]` and leaves the constant at 0.1.4 still finds
 * a `## [0.1.4]` heading further down the file, because every past release left
 * one there. Rolling the constant back to 0.1.3 with the README to match - the
 * shape of a release that forgot both - passed that check with nine assertions
 * green. This compares the constant against the NEWEST cut section instead, so
 * a constant that lags the release fails immediately.
 *
 * Packagist derives the published version from the git tag, so there is no
 * manifest field to compare against; the newest cut changelog section is the
 * in-repo record of what that tag was. `carve/scripts/pre-tag-check.sh` gates
 * the tag itself.
 *
 * Both assertions read BOTH of their sides at run time. No version literal
 * appears in this file: a literal would have to be edited on release too, which
 * is the defect rather than the fix.
 */
class ReleaseVersionTest extends TestCase
{
    /**
     * The newest CUT changelog section, i.e. the first `## [X.Y.Z]` heading,
     * skipping the open `## [Unreleased]` one. That heading is what the release
     * process writes when it cuts a release.
     */
    private function newestReleasedChangelogVersion(): string
    {
        $changelog = $this->read('CHANGELOG.md');

        foreach (explode("\n", $changelog) as $line) {
            if (preg_match('/^## \[?(\d[^\]\s]*)/', $line, $matches) === 1) {
                return $matches[1];
            }
        }

        $this->fail('CHANGELOG.md has no cut "## [X.Y.Z]" section.');
    }

    /**
     * The `Version:` field in the vendored grammar header, which the spec's
     * versioning page names as the spec's version.
     */
    private function vendoredGrammarVersion(): string
    {
        $grammar = $this->read('tests/spec/resources/grammar.ebnf');

        foreach (explode("\n", $grammar) as $line) {
            if (preg_match('/^\s*Version:\s*(\S+)/', $line, $matches) === 1) {
                return $matches[1];
            }
        }

        $this->fail('tests/spec/resources/grammar.ebnf has no "Version:" field.');
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        $contents = file_get_contents($path);
        $this->assertIsString(
            $contents,
            "Cannot read {$relative}. This gate compares two files against each other, "
            . 'so a missing side means the comparison did not happen.',
        );

        return $contents;
    }

    public function testTheLibraryVersionIsTheNewestReleasedChangelogSection(): void
    {
        $changelog = $this->newestReleasedChangelogVersion();

        $this->assertSame(
            $changelog,
            CarveConverter::LIB_VERSION,
            'LIB_VERSION is ' . CarveConverter::LIB_VERSION . ', but the newest cut CHANGELOG '
            . "section is {$changelog}. Whatever reads the constant - `carve --version`, the "
            . 'provenance stamp, an embedder quoting it in a bug report - is naming a release '
            . 'that is not the one running.',
        );
    }

    public function testTheSpecVersionMatchesTheVendoredGrammar(): void
    {
        $grammar = $this->vendoredGrammarVersion();

        $this->assertSame(
            $grammar,
            CarveConverter::SPEC_VERSION,
            'SPEC_VERSION says this library implements Carve ' . CarveConverter::SPEC_VERSION
            . ", but the vendored grammar is Carve {$grammar}. SPEC_VERSION is what "
            . '`carve fmt --stamp` writes into a document and what the stamp reader compares '
            . 'an old marker against, so a stale value tells a reader their document is '
            . 'current when it is not.',
        );
    }
}
