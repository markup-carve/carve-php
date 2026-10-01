<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `CARVE-P2-024`'s `destination-denied` `message` is NORMATIVE: an engine emits
 * one of two exact strings with nothing appended (markup-carve/carve#2686).
 *
 * `target` is already a field on the row, so the message must not repeat it;
 * `nodeType` is `inline` for both sinks, so the message is the only place the
 * sink kind survives.
 *
 * THE TWO STRINGS ARE READ OUT OF THE VENDORED SCHEMA, never hand-copied - four
 * hand-copied literals across four repos is what drifted here. The schema can
 * only gate MEMBERSHIP in the pair, so which sink takes which is asserted
 * against a real render below.
 */
class ABlankedDestinationsMessageNamesItsSinkTest extends TestCase
{
    /**
     * One denied link and one denied image in one document.
     *
     * @var string
     */
    private const TWO_SINKS = "[report me](javascript:one) and ![report me too](vbscript:two)\n";

    /**
     * @return array<string, string> Keyed `link` and `image`
     */
    private static function schemaMessages(): array
    {
        $schema = json_decode(
            (string)file_get_contents(__DIR__ . '/spec/resources/render-loss-report.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        foreach ($schema['properties']['losses']['items']['oneOf'] as $branch) {
            if (($branch['properties']['code']['const'] ?? null) !== 'destination-denied') {
                continue;
            }
            $enum = $branch['properties']['message']['enum'] ?? null;
            self::assertIsArray($enum, 'the schema branch carries no message enum yet');
            self::assertCount(2, $enum);
            $image = array_values(array_filter($enum, static fn (string $m): bool => str_contains($m, 'image')));
            $link = array_values(array_diff($enum, $image));
            self::assertCount(1, $image);
            self::assertCount(1, $link);

            return ['link' => $link[0], 'image' => $image[0]];
        }

        self::fail('the schema has no destination-denied branch');
    }

    /**
     * A target that reports both sinks pairs each row with its own string, in
     * document order: the link's destination first, then the image's source.
     *
     * @return array<string, array{0: string}>
     */
    public static function targetsThatReportBothSinks(): array
    {
        return ['html' => ['create'], 'markdown' => ['markdown']];
    }

    #[DataProvider('targetsThatReportBothSinks')]
    public function testEachSinkTakesItsOwnCanonicalMessage(string $factory): void
    {
        $messages = self::schemaMessages();
        $converter = CarveConverter::$factory();
        $result = $converter->convertWithReport(self::TWO_SINKS);

        self::assertSame($converter->convert(self::TWO_SINKS), $result->value);
        self::assertSame(
            [$messages['link'], $messages['image']],
            array_column($result->losses, 'message'),
            $factory . ' did not pair each sink with its own message',
        );
    }

    /**
     * Nothing is appended, so the message never names the target that is already
     * a field on the row.
     */
    #[DataProvider('targetsThatReportBothSinks')]
    public function testTheMessageNeverRepeatsTheTarget(string $factory): void
    {
        $result = CarveConverter::$factory()->convertWithReport(self::TWO_SINKS);

        foreach ($result->losses as $loss) {
            self::assertStringNotContainsString($loss['target'], $loss['message'], $factory);
        }
    }

    /**
     * An autolink is a link destination, not a third sink.
     */
    public function testAnAutolinkTakesTheDestinationMessage(): void
    {
        $result = CarveConverter::create()->convertWithReport("<data:text/html,three>\n");

        self::assertSame([self::schemaMessages()['link']], array_column($result->losses, 'message'));
    }

    /**
     * ANSI prints a link's target beside the text but never an image source, so
     * it owes ONE row - the sink argument threaded through the shared helper is
     * most likely to regress here.
     */
    public function testAnsiOwesOneLinkRow(): void
    {
        $converter = CarveConverter::ansi();
        $result = $converter->convertWithReport(self::TWO_SINKS);

        self::assertSame($converter->convert(self::TWO_SINKS), $result->value);
        self::assertSame(1, $result->totalLosses);
        self::assertSame([self::schemaMessages()['link']], array_column($result->losses, 'message'));
    }

    /**
     * Every emitted message is in the schema's two-string set, across every
     * target that blanks anything.
     *
     * @return array<string, array{0: string}>
     */
    public static function targetsThatBlank(): array
    {
        return ['html' => ['create'], 'markdown' => ['markdown'], 'ansi' => ['ansi']];
    }

    #[DataProvider('targetsThatBlank')]
    public function testEveryMessageIsInTheSchemaSet(string $factory): void
    {
        $messages = array_values(self::schemaMessages());
        $result = CarveConverter::$factory()->convertWithReport(self::TWO_SINKS);

        self::assertNotSame([], $result->losses, $factory);
        foreach ($result->losses as $loss) {
            self::assertContains($loss['message'], $messages, $factory);
        }
    }
}
