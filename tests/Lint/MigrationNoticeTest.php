<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Cli\Command;
use MarkupCarve\Carve\OptionError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-156 (carve#6499): a migration notice is off by default, on for a document whose line-1 frontmatter declares a
 * version below 0.2 and under the option `upgrade`.
 */
final class MigrationNoticeTest extends TestCase
{
    /**
     * @var string
     */
    private const HEADING = "== A long heading that\n   continues here\n";

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function declarations(): array
    {
        return [
            'no frontmatter' => ['', false],
            'carve 0.1' => ["---\ncarve: 0.1\n---\n", true],
            'carve-version 0.1.13' => ["---\ncarve-version: 0.1.13\n---\n", true],
            'carve 0' => ["---\ncarve: 0\n---\n", true],
            'carve 0.0.9' => ["---\ncarve: 0.0.9\n---\n", true],
            'carve 00.01' => ["---\ncarve: 00.01\n---\n", true],
            'key among others' => ["---\ntitle: T\n  carve: 0.1\n---\n", true],
            'byte-order mark' => ["\u{FEFF}---\ncarve: 0.1\n---\n", true],
            'typed opener' => ["---yaml\ncarve: 0.1\n---\n", true],
            'crlf' => ["---\r\ncarve: 0.1\r\n---\r\n", true],
            'carve 0.2' => ["---\ncarve: 0.2\n---\n", false],
            'carve 0.10' => ["---\ncarve: 0.10\n---\n", false],
            'carve 1.0' => ["---\ncarve: 1.0\n---\n", false],
            'quoted value' => ["---\ncarve: \"0.1\"\n---\n", false],
            'two value tokens' => ["---\ncarve: 0.1 beta\n---\n", false],
            'other key' => ["---\nmycarve: 0.1\n---\n", false],
            'frontmatter below line 1' => ["a\n\n---yaml\ncarve: 0.1\n---\n\n", false],
        ];
    }

    #[DataProvider('declarations')]
    public function testTheDeclarationTurnsTheNoticesOn(string $frontmatter, bool $reported): void
    {
        $this->assertSame($reported, self::reports($frontmatter . self::HEADING, []));
    }

    public function testTheOptionTurnsTheNoticesOn(): void
    {
        $this->assertFalse(self::reports(self::HEADING, []));
        $this->assertFalse(self::reports(self::HEADING, ['upgrade' => false]));
        $this->assertTrue(self::reports(self::HEADING, ['upgrade' => true]));
    }

    public function testRulesWinOverTheDeclarationAndTheOption(): void
    {
        $off = ['rules' => ['deprecated-indented-line-under-heading' => 'off']];
        $this->assertFalse(self::reports("---\ncarve: 0.1\n---\n" . self::HEADING, $off));
        $this->assertFalse(self::reports(self::HEADING, $off + ['upgrade' => true]));
        $this->assertTrue(self::reports(self::HEADING, ['rules' => ['deprecated-indented-line-under-heading' => 'on']]));
    }

    public function testOtherRulesKeepTheirDefaults(): void
    {
        $codes = array_map(static fn ($d): string => $d->code, Carve::lint("{loose}\n- a\n"));
        $this->assertContains('deprecated-loose-attribute', $codes);
    }

    public function testANonBooleanUpgradeIsRefused(): void
    {
        $this->expectException(OptionError::class);
        Carve::lint('a', ['upgrade' => 'yes']);
    }

    public function testTheCommandLineSwitch(): void
    {
        [$status, $out] = self::runCommand(['lint', '-'], self::HEADING);
        $this->assertSame([0, ''], [$status, $out]);

        [$status, $out] = self::runCommand(['lint', '--upgrade', '-'], self::HEADING);
        $this->assertSame(1, $status);
        $this->assertStringContainsString('<stdin>:2:1: warning deprecated-indented-line-under-heading ', $out);

        [$status, , $err] = self::runCommand(['render', '--upgrade', '-'], self::HEADING);
        $this->assertSame(2, $status);
        $this->assertSame("carve[usage]: --upgrade is for lint\n", $err);
    }

    /**
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return bool
     */
    private static function reports(string $source, array $options): bool
    {
        foreach (Carve::lint($source, $options) as $d) {
            if ($d->code === 'deprecated-indented-line-under-heading') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string> $args
     * @param string $source
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function runCommand(array $args, string $source): array
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        self::assertNotFalse($err);
        fwrite($in, $source);
        rewind($in);
        $status = Command::main($args, $in, $out, $err);
        rewind($out);
        rewind($err);

        return [$status, (string)stream_get_contents($out), (string)stream_get_contents($err)];
    }
}
