<?php

declare(strict_types=1);

namespace Carve\Test;

use Carve\CarveConverter;
use Carve\Extension\AutolinkExtension;
use Carve\Extension\CitationsExtension;
use Carve\Extension\MentionsExtension;
use Carve\Extension\SmartQuotesExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function basename;
use function file_get_contents;
use function json_decode;

#[Group('optional-corpus')]
class OptionalCorpusTest extends TestCase
{
    /**
     * @throws \RuntimeException
     *
     * @return array<string, array{slug: string, feature: string, crv: string, html: string}>
     */
    public static function optionalCorpusProvider(): array
    {
        $dir = __DIR__ . '/spec/tests/corpus-optional';
        $manifestPath = $dir . '/manifest.json';

        if (!file_exists($manifestPath)) {
            throw new RuntimeException(
                "Optional Tier-2 corpus manifest not found at {$manifestPath}.\n"
                . "Initialize the submodule:\n  git submodule update --init",
            );
        }

        $manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $cases = [];

        foreach ($manifest['cases'] ?? [] as $entry) {
            $slug = basename($entry['slug']);
            $crvPath = $dir . '/' . $slug . '.crv';
            $htmlPath = $dir . '/' . $slug . '.html';
            if (!file_exists($crvPath) || !file_exists($htmlPath)) {
                continue;
            }

            $cases[$slug] = [
                'slug' => $slug,
                'feature' => $entry['feature'],
                'crv' => (string)file_get_contents($crvPath),
                'html' => (string)file_get_contents($htmlPath),
            ];
        }

        return $cases;
    }

    #[DataProvider('optionalCorpusProvider')]
    public function testOptionalCorpus(string $slug, string $feature, string $crv, string $html): void
    {
        $converter = $this->createConverterForFeature($feature);
        if ($converter === null) {
            $this->markTestSkipped('Optional Tier-2 feature not supported by carve-php: ' . $feature);
        }

        $actual = $converter->convert($crv);

        $this->assertSame(
            $this->normalize($html),
            $this->normalize($actual),
            'Optional Tier-2 corpus mismatch for ' . $slug,
        );
    }

    protected function createConverterForFeature(string $feature): ?CarveConverter
    {
        $converter = new CarveConverter();

        return match ($feature) {
            'social-link-templates' => $converter->addExtension(new MentionsExtension(
                mentionUrl: '/users/{name}',
                tagUrl: '/topics/{name}',
            )),
            'smart-quotes-locale-de' => $converter->addExtension(new SmartQuotesExtension(locale: 'de')),
            'bare-url-autolink' => $converter->addExtension(new AutolinkExtension()),
            'citations-numbered' => $converter->addExtension(new CitationsExtension()),
            'citations-author-date' => $converter->addExtension(new CitationsExtension('author-date')),
            'emoji-map' => null,
            default => null,
        };
    }

    protected function normalize(string $s): string
    {
        $s = (string)preg_replace('/[ \t]+$/m', '', $s);

        return rtrim($s, "\n");
    }
}
