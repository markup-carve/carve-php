<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Extension;

use InvalidArgumentException;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\Name;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NameTest extends TestCase
{
    /**
     * Options a factory requires, so its case can be built without configuration from the host.
     *
     * @var array<string, array<string, mixed>>
     */
    private const REQUIRED = [
        'default_attributes' => ['defaults' => ['table' => ['class' => 'table']]],
        'fenced_render' => ['language' => 'dot'],
    ];

    /**
     * @return array<string, array{\MarkupCarve\Carve\Extension\Name}>
     */
    public static function names(): array
    {
        $cases = [];
        foreach (Name::cases() as $name) {
            $cases[$name->value] = [$name];
        }

        return $cases;
    }

    #[DataProvider('names')]
    public function testEveryNameBuilds(Name $name): void
    {
        $extensions = $name->create(self::REQUIRED[$name->value] ?? []);

        $this->assertNotSame([], $extensions);
        $this->assertSame('<p>a</p>', Carve::render("a\n", ['extensions' => $extensions]));
    }

    public function testOptionsBindSnakeCaseToTheFactoryParameter(): void
    {
        $extensions = Name::Wikilinks->create(['css_class' => 'wiki', 'url_template' => '/w/{page}']);

        $html = Carve::render("See :wiki[Main Page].\n", ['extensions' => $extensions]);

        $this->assertStringContainsString('href="/w/main-page"', $html);
        $this->assertStringContainsString('class="wiki"', $html);
    }

    public function testAnUnknownOptionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown Carve extension option "colour" for "color_swatch".');

        Name::ColorSwatch->create(['colour' => true]);
    }

    public function testAnOptionOfTheWrongTypeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Name::ExternalLinks->create(['nofollow' => 'yes']);
    }

    public function testExternalLinksLeaveInternalHostsAlone(): void
    {
        $extensions = Name::ExternalLinks->create(['internal_hosts' => ['Example.com']]);
        $html = Carve::render("<https://example.com/a> <https://other.org/b>\n", ['extensions' => $extensions]);

        $this->assertStringContainsString('<a href="https://example.com/a">', $html);
        $this->assertStringContainsString('<a href="https://other.org/b" rel="noopener noreferrer" target="_blank">', $html);
    }

    public function testValuesAreTheConfigurationNames(): void
    {
        $this->assertContains('code_callouts', Name::values());
        $this->assertSame(Name::TableOfContents, Name::from('table_of_contents'));
        $this->assertCount(count(Name::cases()), array_unique(Name::values()));
    }
}
