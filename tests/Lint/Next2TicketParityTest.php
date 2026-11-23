<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Next2TicketParityTest extends TestCase
{
    /**
     * @return array<string, array{string, string, list<array{string, int, int}>}>
     */
    public static function cases(): array
    {
        return [
            '#5368 1' => [
                ':: term
: :: term
 |',
                'caption-looking-paragraph',
                [],
            ],
            '#5361 1' => [
                '  >|
  > [a]: <>',
                'definition-in-container',
                [],
            ],
            '#5355 1' => [
                'a |={<}',
                'emphasis-marker-before-attributes',
                [],
            ],
            '#5355 2' => [
                '+ |={<}',
                'emphasis-marker-before-attributes',
                [],
            ],
            '#5372 1' => [
                '{.c}
[^f]: a',
                'unused-footnote-definition',
                [
                    ['unused-footnote-definition', 5, 9],
                ],
            ],
            '#5359 1' => [
                'a { #id }',
                'unattached-inline-attribute',
                [
                    ['unattached-inline-attribute', 2, 9],
                ],
            ],
            '#5359 2' => [
                '+ { #id }',
                'unattached-inline-attribute',
                [
                    ['unattached-inline-attribute', 2, 9],
                ],
            ],
            '#5358 1' => [
                '> [a]:',
                'empty-reference-definition',
                [
                    ['empty-reference-definition', 2, 6],
                ],
            ],
            '#5356 1' => [
                '> * * *',
                'deprecated-spaced-thematic-break',
                [
                    ['deprecated-spaced-thematic-break', 2, 7],
                ],
            ],
            '#5356 2' => [
                '>       - - -',
                'deprecated-spaced-thematic-break',
                [
                    ['deprecated-spaced-thematic-break', 8, 13],
                ],
            ],
            '#5371 1' => [
                '>: > [!NOTE]',
                'github-alert-marker',
                [],
            ],
            '#5362 1' => [
                ':: t
[a]: u',
                'definition-in-container',
                [],
            ],
            '#5362 2' => [
                ':: t
[^f]: [a]:',
                'definition-in-container',
                [],
            ],
            '#5354 1' => [
                ':: t
> ---',
                'thematic-break-spelling',
                [],
            ],
            '#5353 1' => [
                ':::
{#id}
:::',
                'unattached-block-attribute',
                [
                    ['unattached-block-attribute', 4, 9],
                ],
            ],
            '#5353 2' => [
                '    :::
{.c}
:::',
                'unattached-block-attribute',
                [
                    ['unattached-block-attribute', 8, 12],
                ],
            ],
            '#5352 1' => [
                '* {#id}',
                'unattached-block-attribute',
                [
                    ['unattached-block-attribute', 2, 7],
                ],
            ],
            '#5352 2' => [
                '> {#id}',
                'unattached-block-attribute',
                [
                    ['unattached-block-attribute', 2, 7],
                ],
            ],
            '#5352 3' => [
                ':: t
: {#id}',
                'unattached-block-attribute',
                [
                    ['unattached-block-attribute', 7, 12],
                ],
            ],
            '#5350 1' => [
                '::::
   %%%%
   :::',
                'colon-fence-length-mismatch',
                [],
            ],
            '#5347 1' => [
                '* a

	::::',
                'list-item-block-overindented',
                [
                    ['list-item-block-overindented', 6, 10],
                ],
            ],
            '#5347 2' => [
                '* [a]: u
	::::',
                'list-item-block-overindented',
                [
                    ['list-item-block-overindented', 10, 14],
                ],
            ],
            '#5377 1' => [
                '> %%%%
>%%%%',
                'blockquote-marker-without-space',
                [
                    ['blockquote-marker-without-space', 7, 12],
                ],
            ],
            '#5376 1' => [
                '	>:::
## H
i) i) %%%%
       %%%' . ' ' . '
>  a <b>',
                'bare-html-tag',
                [],
            ],
            '#5364 1' => [
                '2) ==
	- [x]',
                'list-item-body-below-content-column',
                [],
            ],
            '#5364 2' => [
                '- a

	- b',
                'list-item-body-below-content-column',
                [],
            ],
            '#5357 1' => [
                '>###
> * * *',
                'deprecated-spaced-thematic-break',
                [],
            ],
            '#5346 1' => [
                '- a

   %%x',
                'list-item-block-overindented',
                [],
            ],
            '#5346 2' => [
                '* $$x$$
   %% #region r',
                'list-item-block-overindented',
                [],
            ],
            '#5374 1' => [
                'a. x
i) y',
                'ordered-list-kind-split',
                [],
            ],
            '#5373 1' => [
                '> a
> [ ]: u',
                'blank-reference-label',
                [
                    ['blank-reference-label', 6, 10],
                ],
            ],
            '#5370 1' => [
                '[^f]: %%x
^ Caption',
                'unattached-caption',
                [
                    ['unattached-caption', 10, 19],
                ],
            ],
            '#5369 1' => [
                '> 10.
>    ^ Caption',
                'unattached-caption',
                [
                    ['unattached-caption', 11, 20],
                ],
            ],
            '#5360 1' => [
                ':: term
: :: t
: %%',
                'description-separator-below-content-column',
                [],
            ],
            '#5360 2' => [
                ':: term
: ::::
   :: t
: ::::',
                'description-separator-below-content-column',
                [],
            ],
            '#5349 1' => [
                '::::
:::',
                'colon-fence-length-mismatch',
                [
                    ['colon-fence-length-mismatch', 5, 8],
                ],
            ],
            '#5349 2' => [
                '::::
:::
:::
:::',
                'colon-fence-length-mismatch',
                [
                    ['colon-fence-length-mismatch', 5, 8],
                    ['colon-fence-length-mismatch', 13, 16],
                ],
            ],
            '#5375 1' => [
                '[^f]: > [^m]: key [ref][]
> [',
                'block-opener-without-blank-line',
                [
                    ['block-opener-without-blank-line', 26, 27],
                ],
            ],
            '#5375 2' => [
                '    * > > +
> >',
                'block-opener-without-blank-line',
                [
                    ['block-opener-without-blank-line', 12, 13],
                ],
            ],
            '#5363 1' => [
                '- %%%
  %%% x',
                'comment-fence-closer-with-text',
                [
                    ['comment-fence-closer-with-text', 6, 13],
                ],
            ],
            '#5351 1' => [
                '  :::
  ::::',
                'colon-fence-length-mismatch',
                [
                    ['colon-fence-length-mismatch', 8, 12],
                ],
            ],
            '#5351 2' => [
                '* ::: warning
   ::::
   :::',
                'colon-fence-length-mismatch',
                [
                    ['colon-fence-length-mismatch', 17, 21],
                ],
            ],
            '#5345 1' => [
                '* a

   ![alt](i.png)',
                'list-item-block-overindented',
                [
                    ['list-item-block-overindented', 8, 21],
                ],
            ],
            '#5343 1' => [
                ':: term
: > :: word',
                'block-opener-overindented',
                [],
            ],
            '#5343 2' => [
                '[^n]: > :: word',
                'block-opener-overindented',
                [],
            ],
            '#5343 3' => [
                '- > :: word',
                'block-opener-overindented',
                [],
            ],
            '#5343 4' => [
                ':: term
: > > :: w',
                'block-opener-overindented',
                [],
            ],
            '#5367 1' => [
                '2) ![alt](i.png)
   : word',
                'caption-looking-paragraph',
                [
                    ['caption-looking-paragraph', 20, 21],
                ],
            ],
            '#5367 2' => [
                '>![](i.png)
: {%c%}',
                'caption-looking-paragraph',
                [
                    ['caption-looking-paragraph', 12, 13],
                ],
            ],
            '#5366 1' => [
                '	2) >:

	    ::',
                'list-item-body-below-content-column',
                [],
            ],
            '#5365 1' => [
                '> 10. %%
>    : a',
                'list-item-body-below-content-column',
                [
                    ['list-item-body-below-content-column', 14, 17],
                ],
            ],
            '#5344 1' => [
                '{.c}
[^f]: > x',
                'block-opener-overindented',
                [],
            ],
            '#5348 1' => [
                '	> * >',
                'list-item-block-overindented',
                [],
            ],
            '#5348 2' => [
                '	> i) :: term',
                'list-item-block-overindented',
                [],
            ],
            '#5365 duplicate variant' => [
                "a. :: term [ref][]\n\n  : word <b>\n\n{{x.crv}}",
                'list-item-body-below-content-column',
                [
                    ['list-item-body-below-content-column', 22, 32],
                ],
            ],
            // carve#6176: a term is inline content, so the block on its lazy line is read too.
            '#5359 term variant' => [
                ":: term\n   : { #id }",
                'unattached-inline-attribute',
                [
                    ['unattached-inline-attribute', 13, 20],
                ],
            ],
            '#5362 indented term variant' => [
                "   :: term\n: #H\n  [a]: u\n::::\n",
                'definition-in-container',
                [
                    ['definition-in-container', 18, 22],
                ],
            ],
        ];
    }

    /**
     * @param string $source
     * @param string $rule
     * @param list<array{string, int, int}> $expected
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testRule(string $source, string $rule, array $expected): void
    {
        $actual = [];
        foreach (Carve::lint($source, ['upgrade' => true]) as $diagnostic) {
            if ($diagnostic->code === $rule) {
                $actual[] = [$diagnostic->code, $diagnostic->startOffset, $diagnostic->endOffset];
            }
        }
        self::assertSame($expected, $actual);
    }

    public function testEscapedCommentRecoveryStopsAtNewComment(): void
    {
        foreach (['<b>' => 'bare-html-tag', '>x' => 'blockquote-marker-without-space'] as $line => $rule) {
            $source = "> %%%\n> %%%\n\n%%%\n" . $line . "\n%%%";
            $codes = array_map(static fn ($diagnostic): string => $diagnostic->code, Carve::lint($source));
            self::assertNotContains($rule, $codes);
        }
    }

    public function testEscapedCommentRecoveryStopsAtCodeFence(): void
    {
        $source = "> %%%\n> %%%\n\n```\n>x\n```";
        $codes = array_map(static fn ($diagnostic): string => $diagnostic->code, Carve::lint($source));
        self::assertNotContains('blockquote-marker-without-space', $codes);
    }
}
