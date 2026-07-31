<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Profile;
use PHPUnit\Framework\TestCase;

/**
 * carve-php is the reference for the shared profile battery, so the file
 * checked into the spec repo has to match what this build actually produces.
 * Nothing checked that: the battery was generated once and never re-verified,
 * which is how three engines drifted into the same profile defect unnoticed.
 */
class ProfileFixtureParityTest extends TestCase
{
    public function testTheCheckedInBatteryMatchesThisBuild(): void
    {
        $path = __DIR__ . '/../spec/tests/profile-fixtures.json';
        $fixtures = json_decode((string)file_get_contents($path), true);
        $this->assertIsArray($fixtures);
        $this->assertNotSame([], $fixtures, 'the battery is empty');

        $factories = [
            'full' => fn (): Profile => Profile::full(),
            'article' => fn (): Profile => Profile::article(),
            'comment' => fn (): Profile => Profile::comment(),
            'minimal' => fn (): Profile => Profile::minimal(),
        ];

        foreach ($fixtures as $name => $case) {
            $factory = $factories[$case['profile']] ?? null;
            $this->assertNotNull($factory, "unknown profile id '{$case['profile']}' in fixture '{$name}'");

            $converter = new CarveConverter(profile: $factory());
            $this->assertSame(
                rtrim($case['html'], "\n"),
                rtrim($converter->convert($case['carve']), "\n"),
                "fixture '{$name}' no longer matches this build - regenerate tests/profile-fixtures.json in the spec repo",
            );
        }
    }
}
