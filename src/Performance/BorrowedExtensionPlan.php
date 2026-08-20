<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Performance;

use MarkupCarve\Carve\Extension\ExtensionInterface;
use MarkupCarve\Carve\Extension\ExternalLinksExtension;
use MarkupCarve\Carve\Extension\HeadingNumbersExtension;
use MarkupCarve\Carve\Extension\HeadingPermalinksExtension;
use MarkupCarve\Carve\Extension\LowercaseHeadingIdsExtension;

/**
 * Compiler from configured extensions to borrowed-layout events.
 * Unknown or source-active unsupported extensions return null and preserve the
 * authoritative AST fallback.
 */
final class BorrowedExtensionPlan
{
    /**
     * @param array<\MarkupCarve\Carve\Extension\ExtensionInterface> $extensions
     * @param string $source
     *
     * @return array{
     *   headingNumbers: array{minLevel: int}|null,
     *   headingPermalinks: array{symbol: string, position: string, cssClass: string, ariaLabel: string, levels: array<int>, showOnHover: bool, copyToClipboard: bool}|null,
     *   externalLinks: array{internalHosts: array<string>, target: string, rel: string, nofollow: bool}|null,
     *   lowercaseIds: bool
     * }|null
     */
    public static function compile(array $extensions, string $source): ?array
    {
        $plan = ['headingNumbers' => null, 'headingPermalinks' => null, 'externalLinks' => null, 'lowercaseIds' => false];
        foreach ($extensions as $extension) {
            $class = $extension::class;
            if (isset(self::EVENTS[$class])) {
                self::addEvent($plan, $extension);

                continue;
            }
            if (!self::inactive($class, $source)) {
                return null;
            }
        }

        return $plan;
    }

    /**
     * @var array<class-string, string>
     */
    private const EVENTS = [
        'MarkupCarve\\Carve\\Extension\\HeadingNumbersExtension' => 'headingNumbers',
        'MarkupCarve\\Carve\\Extension\\HeadingPermalinksExtension' => 'headingPermalinks',
        'MarkupCarve\\Carve\\Extension\\ExternalLinksExtension' => 'externalLinks',
        'MarkupCarve\\Carve\\Extension\\LowercaseHeadingIdsExtension' => 'lowercaseIds',
    ];

    private static function inactive(string $class, string $source): bool
    {
        return match ($class) {
            'MarkupCarve\\Carve\\Extension\\AutolinkExtension' => !self::couldAutolink($source),
            'MarkupCarve\\Carve\\Extension\\CitationsExtension' => !str_contains($source, '[@'),
            'MarkupCarve\\Carve\\Extension\\CodeCalloutsExtension' =>
                preg_match('/(?:^|\n)(?:.*<\d+>[ \t]*|<\d+> )/m', $source) !== 1,
            'MarkupCarve\\Carve\\Extension\\SemanticSpanExtension' =>
                preg_match('/:(?:samp|var|cite|dfn)\[|\{[^}\n]*(?:samp|var|cite|dfn)(?:[ =}]|$)/', $source) !== 1,
            'MarkupCarve\\Carve\\Extension\\ListTableExtension' => !str_contains($source, '::: list-table'),
            'MarkupCarve\\Carve\\Extension\\DetailsExtension' => !str_contains($source, '::: details'),
            'MarkupCarve\\Carve\\Extension\\SpoilerExtension' =>
                !str_contains($source, ':spoiler[') && !str_contains($source, '::: spoiler'),
            'MarkupCarve\\Carve\\Extension\\TabsExtension' => !str_contains($source, '::: tabs'),
            'MarkupCarve\\Carve\\Extension\\GlossaryExtension' =>
                !str_contains($source, ':term[') && !str_contains($source, '::: glossary'),
            'MarkupCarve\\Carve\\Extension\\IndexExtension' => !str_contains($source, ':index['),
            'MarkupCarve\\Carve\\Extension\\CodeGroupExtension' => !str_contains($source, '::: code-group'),
            'MarkupCarve\\Carve\\Extension\\TableOfContentsExtension' => !str_contains($source, '::: toc'),
            'MarkupCarve\\Carve\\Extension\\WikilinksExtension' => !str_contains($source, '[['),
            'MarkupCarve\\Carve\\Extension\\ColorSwatchExtension' => !str_contains($source, ':color['),
            // BorrowedHtmlLayout already rejects non-ASCII source.
            'MarkupCarve\\Carve\\Extension\\AsciiHeadingIdsExtension' => true,
            'MarkupCarve\\Carve\\Extension\\MathBlockExtension' =>
                preg_match('/(?:^|\n)`{3,}math(?:\s|$)/', $source) !== 1,
            default => false,
        };
    }

    /**
     * @param array{
     *   headingNumbers: array{minLevel: int}|null,
     *   headingPermalinks: array{symbol: string, position: string, cssClass: string, ariaLabel: string, levels: array<int>, showOnHover: bool, copyToClipboard: bool}|null,
     *   externalLinks: array{internalHosts: array<string>, target: string, rel: string, nofollow: bool}|null,
     *   lowercaseIds: bool
     * } $plan
     * @param \MarkupCarve\Carve\Extension\ExtensionInterface $extension
     */
    private static function addEvent(array &$plan, ExtensionInterface $extension): void
    {
        if ($extension instanceof HeadingNumbersExtension) {
            $plan['headingNumbers'] = $extension->borrowedHtmlConfiguration();
        } elseif ($extension instanceof HeadingPermalinksExtension) {
            $plan['headingPermalinks'] = $extension->borrowedHtmlConfiguration();
        } elseif ($extension instanceof ExternalLinksExtension) {
            $plan['externalLinks'] = $extension->borrowedHtmlConfiguration();
        } elseif ($extension instanceof LowercaseHeadingIdsExtension) {
            $plan['lowercaseIds'] = true;
        }
    }

    private static function couldAutolink(string $source): bool
    {
        $text = preg_replace('/(?:^|\n)\[[^\]\n]+\]:[^\n]*/', "\n", $source);
        $text = preg_replace('/\]\([^\)\n]*\)/', ']()', $text ?? $source);

        return preg_match('/(?:https?|mailto):|[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $text ?? $source) === 1;
    }
}
