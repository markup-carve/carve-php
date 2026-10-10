<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\CitationsExtension;
use MarkupCarve\Carve\Extension\SmartQuotesExtension;
use MarkupCarve\Carve\Node\Inline\CitationGroup;
use MarkupCarve\Carve\Node\Inline\SmartPunctuation;
use PHPUnit\Framework\TestCase;

class SmartQuoteRulesTest extends TestCase
{
    public function testJavaScriptRuleVectors(): void
    {
        $converter = new CarveConverter();
        $cases = [
            ['\\{{%%}"q"', '<p>{“q”</p>', false],
            ['({%%}"q"', '<p>(“q”</p>', false],
            ['{%%}"q"', '<p>“q”</p>', false],
            ['x{%%}"q"', '<p>x”q”</p>', false],
            ['say {%%}\'word', '<p>say ’word</p>', false],
            ['"{%%}\'word', '<p>“‘word</p>', false],
            ['\\{{%%}\'q\'', '<p>{‘q’</p>', false],
            ['word-{%%}" ', '<p>word-”</p>', false],
            ['word-{%%}"q"', '<p>word-“q”</p>', false],
            ['word-{%%}\' ', '<p>word-’</p>', false],
            ['word-{%%}\'q\'', '<p>word-‘q’</p>', false],
            ['word--{%%}" ', '<p>word–”</p>', false],
            ['word--{%%}"q"', '<p>word–“q”</p>', false],
            ['word--{%%}\' ', '<p>word–’</p>', false],
            ['word--{%%}\'q\'', '<p>word–‘q’</p>', false],
            ['word---{%%}" ', '<p>word—”</p>', false],
            ['word---{%%}"q"', '<p>word—“q”</p>', false],
            ['word---{%%}\' ', '<p>word—’</p>', false],
            ['word---{%%}\'q\'', '<p>word—‘q’</p>', false],
            ['word–{%%}" ', '<p>word–”</p>', false],
            ['word–{%%}"q"', '<p>word–“q”</p>', false],
            ['word–{%%}\' ', '<p>word–’</p>', false],
            ['word–{%%}\'q\'', '<p>word–‘q’</p>', false],
            ['word—{%%}" ', '<p>word—”</p>', false],
            ['word—{%%}"q"', '<p>word—“q”</p>', false],
            ['word—{%%}\' ', '<p>word—’</p>', false],
            ['word—{%%}\'q\'', '<p>word—‘q’</p>', false],
            ['\\{{% hidden %}"q"', '<p>{“q”</p>', false],
            ['({% hidden %}"q"', '<p>(“q”</p>', false],
            ['{% hidden %}"q"', '<p>“q”</p>', false],
            ['x{% hidden %}"q"', '<p>x”q”</p>', false],
            ['say {% hidden %}\'word', '<p>say ’word</p>', false],
            ['"{% hidden %}\'word', '<p>“‘word</p>', false],
            ['\\{{% hidden %}\'q\'', '<p>{‘q’</p>', false],
            ['word-{% hidden %}" ', '<p>word-”</p>', false],
            ['word-{% hidden %}"q"', '<p>word-“q”</p>', false],
            ['word-{% hidden %}\' ', '<p>word-’</p>', false],
            ['word-{% hidden %}\'q\'', '<p>word-‘q’</p>', false],
            ['word--{% hidden %}" ', '<p>word–”</p>', false],
            ['word--{% hidden %}"q"', '<p>word–“q”</p>', false],
            ['word--{% hidden %}\' ', '<p>word–’</p>', false],
            ['word--{% hidden %}\'q\'', '<p>word–‘q’</p>', false],
            ['word---{% hidden %}" ', '<p>word—”</p>', false],
            ['word---{% hidden %}"q"', '<p>word—“q”</p>', false],
            ['word---{% hidden %}\' ', '<p>word—’</p>', false],
            ['word---{% hidden %}\'q\'', '<p>word—‘q’</p>', false],
            ['word–{% hidden %}" ', '<p>word–”</p>', false],
            ['word–{% hidden %}"q"', '<p>word–“q”</p>', false],
            ['word–{% hidden %}\' ', '<p>word–’</p>', false],
            ['word–{% hidden %}\'q\'', '<p>word–‘q’</p>', false],
            ['word—{% hidden %}" ', '<p>word—”</p>', false],
            ['word—{% hidden %}"q"', '<p>word—“q”</p>', false],
            ['word—{% hidden %}\' ', '<p>word—’</p>', false],
            ['word—{% hidden %}\'q\'', '<p>word—‘q’</p>', false],
            ['\\{{%%}{% hidden %}"q"', '<p>{“q”</p>', false],
            ['({%%}{% hidden %}"q"', '<p>(“q”</p>', false],
            ['{%%}{% hidden %}"q"', '<p>“q”</p>', false],
            ['x{%%}{% hidden %}"q"', '<p>x”q”</p>', false],
            ['say {%%}{% hidden %}\'word', '<p>say ’word</p>', false],
            ['"{%%}{% hidden %}\'word', '<p>“‘word</p>', false],
            ['\\{{%%}{% hidden %}\'q\'', '<p>{‘q’</p>', false],
            ['word-{%%}{% hidden %}" ', '<p>word-”</p>', false],
            ['word-{%%}{% hidden %}"q"', '<p>word-“q”</p>', false],
            ['word-{%%}{% hidden %}\' ', '<p>word-’</p>', false],
            ['word-{%%}{% hidden %}\'q\'', '<p>word-‘q’</p>', false],
            ['word--{%%}{% hidden %}" ', '<p>word–”</p>', false],
            ['word--{%%}{% hidden %}"q"', '<p>word–“q”</p>', false],
            ['word--{%%}{% hidden %}\' ', '<p>word–’</p>', false],
            ['word--{%%}{% hidden %}\'q\'', '<p>word–‘q’</p>', false],
            ['word---{%%}{% hidden %}" ', '<p>word—”</p>', false],
            ['word---{%%}{% hidden %}"q"', '<p>word—“q”</p>', false],
            ['word---{%%}{% hidden %}\' ', '<p>word—’</p>', false],
            ['word---{%%}{% hidden %}\'q\'', '<p>word—‘q’</p>', false],
            ['word–{%%}{% hidden %}" ', '<p>word–”</p>', false],
            ['word–{%%}{% hidden %}"q"', '<p>word–“q”</p>', false],
            ['word–{%%}{% hidden %}\' ', '<p>word–’</p>', false],
            ['word–{%%}{% hidden %}\'q\'', '<p>word–‘q’</p>', false],
            ['word—{%%}{% hidden %}" ', '<p>word—”</p>', false],
            ['word—{%%}{% hidden %}"q"', '<p>word—“q”</p>', false],
            ['word—{%%}{% hidden %}\' ', '<p>word—’</p>', false],
            ['word—{%%}{% hidden %}\'q\'', '<p>word—‘q’</p>', false],
            ['[x]{.c}"q"', '<p><span class="c">x</span>”q”</p>', false],
            ['[x]{.c}{%%}"q"', '<p><span class="c">x</span>”q”</p>', false],
            ['word-"{%%}q"', '<p>word-“q”</p>', false],
            ['\'one {%%}\'two\' end\'', '<p>‘one ’two’ end’</p>', false],
            ['word-"', 'word-”', true],
            ['word-\'', 'word-’', true],
            ['word-" ', 'word-”', true],
            ['word-\' ', 'word-’', true],
            ['word-"	', 'word-”', true],
            ['word-\'	', 'word-’', true],
            [
                'word-"
', 'word-”', true,
            ],
            [
                'word-\'
', 'word-’', true,
            ],
            ['word-" ', 'word-”', true],
            ['word-\' ', 'word-’', true],
            ['word-""', 'word-”', true],
            ['word-\'"', 'word-’', true],
            ['word-"\'', 'word-”', true],
            ['word-\'\'', 'word-’', true],
            ['word-".', 'word-”', true],
            ['word-\'.', 'word-’', true],
            ['word-",', 'word-”', true],
            ['word-\',', 'word-’', true],
            ['word-";', 'word-”', true],
            ['word-\';', 'word-’', true],
            ['word-":', 'word-”', true],
            ['word-\':', 'word-’', true],
            ['word-"!', 'word-”', true],
            ['word-\'!', 'word-’', true],
            ['word-"?', 'word-”', true],
            ['word-\'?', 'word-’', true],
            ['word-")', 'word-”', true],
            ['word-\')', 'word-’', true],
            ['word-"]', 'word-”', true],
            ['word-\']', 'word-’', true],
            ['word–"', 'word–”', true],
            ['word–\'', 'word–’', true],
            ['word–" ', 'word–”', true],
            ['word–\' ', 'word–’', true],
            ['word–"	', 'word–”', true],
            ['word–\'	', 'word–’', true],
            [
                'word–"
', 'word–”', true,
            ],
            [
                'word–\'
', 'word–’', true,
            ],
            ['word–" ', 'word–”', true],
            ['word–\' ', 'word–’', true],
            ['word–""', 'word–”', true],
            ['word–\'"', 'word–’', true],
            ['word–"\'', 'word–”', true],
            ['word–\'\'', 'word–’', true],
            ['word–".', 'word–”', true],
            ['word–\'.', 'word–’', true],
            ['word–",', 'word–”', true],
            ['word–\',', 'word–’', true],
            ['word–";', 'word–”', true],
            ['word–\';', 'word–’', true],
            ['word–":', 'word–”', true],
            ['word–\':', 'word–’', true],
            ['word–"!', 'word–”', true],
            ['word–\'!', 'word–’', true],
            ['word–"?', 'word–”', true],
            ['word–\'?', 'word–’', true],
            ['word–")', 'word–”', true],
            ['word–\')', 'word–’', true],
            ['word–"]', 'word–”', true],
            ['word–\']', 'word–’', true],
            ['word—"', 'word—”', true],
            ['word—\'', 'word—’', true],
            ['word—" ', 'word—”', true],
            ['word—\' ', 'word—’', true],
            ['word—"	', 'word—”', true],
            ['word—\'	', 'word—’', true],
            [
                'word—"
', 'word—”', true,
            ],
            [
                'word—\'
', 'word—’', true,
            ],
            ['word—" ', 'word—”', true],
            ['word—\' ', 'word—’', true],
            ['word—""', 'word—”', true],
            ['word—\'"', 'word—’', true],
            ['word—"\'', 'word—”', true],
            ['word—\'\'', 'word—’', true],
            ['word—".', 'word—”', true],
            ['word—\'.', 'word—’', true],
            ['word—",', 'word—”', true],
            ['word—\',', 'word—’', true],
            ['word—";', 'word—”', true],
            ['word—\';', 'word—’', true],
            ['word—":', 'word—”', true],
            ['word—\':', 'word—’', true],
            ['word—"!', 'word—”', true],
            ['word—\'!', 'word—’', true],
            ['word—"?', 'word—”', true],
            ['word—\'?', 'word—’', true],
            ['word—")', 'word—”', true],
            ['word—\')', 'word—’', true],
            ['word—"]', 'word—”', true],
            ['word—\']', 'word—’', true],
            ['"Interrupted---" he said.', '<p>“Interrupted—” he said.</p>', false],
            ['\'Interrupted---\' he said.', '<p>‘Interrupted—’ he said.</p>', false],
            ['word-"Hello"', '<p>word-“Hello”</p>', false],
            ['word-\'Hello\'', '<p>word-‘Hello’</p>', false],
            ['word–"Hello"', '<p>word–“Hello”</p>', false],
            ['word–\'Hello\'', '<p>word–‘Hello’</p>', false],
            ['word—"Hello"', '<p>word—“Hello”</p>', false],
            ['word—\'Hello\'', '<p>word—‘Hello’</p>', false],
            ['\'tis here', '<p>’tis here</p>', false],
            ['\'tis\'', '<p>‘tis’</p>', false],
            ['\'TIS here', '<p>’TIS here</p>', false],
            ['\'TIS\'', '<p>‘TIS’</p>', false],
            ['\'tisn here', '<p>’tisn here</p>', false],
            ['\'tisn\'', '<p>‘tisn’</p>', false],
            ['\'TISN here', '<p>’TISN here</p>', false],
            ['\'TISN\'', '<p>‘TISN’</p>', false],
            ['\'twas here', '<p>’twas here</p>', false],
            ['\'twas\'', '<p>‘twas’</p>', false],
            ['\'TWAS here', '<p>’TWAS here</p>', false],
            ['\'TWAS\'', '<p>‘TWAS’</p>', false],
            ['\'twasn here', '<p>’twasn here</p>', false],
            ['\'twasn\'', '<p>‘twasn’</p>', false],
            ['\'TWASN here', '<p>’TWASN here</p>', false],
            ['\'TWASN\'', '<p>‘TWASN’</p>', false],
            ['\'twere here', '<p>’twere here</p>', false],
            ['\'twere\'', '<p>‘twere’</p>', false],
            ['\'TWERE here', '<p>’TWERE here</p>', false],
            ['\'TWERE\'', '<p>‘TWERE’</p>', false],
            ['\'twill here', '<p>’twill here</p>', false],
            ['\'twill\'', '<p>‘twill’</p>', false],
            ['\'TWILL here', '<p>’TWILL here</p>', false],
            ['\'TWILL\'', '<p>‘TWILL’</p>', false],
            ['\'twould here', '<p>’twould here</p>', false],
            ['\'twould\'', '<p>‘twould’</p>', false],
            ['\'TWOULD here', '<p>’TWOULD here</p>', false],
            ['\'TWOULD\'', '<p>‘TWOULD’</p>', false],
            ['\'em here', '<p>’em here</p>', false],
            ['\'em\'', '<p>‘em’</p>', false],
            ['\'EM here', '<p>’EM here</p>', false],
            ['\'EM\'', '<p>‘EM’</p>', false],
            ['\'cause here', '<p>’cause here</p>', false],
            ['\'cause\'', '<p>‘cause’</p>', false],
            ['\'CAUSE here', '<p>’CAUSE here</p>', false],
            ['\'CAUSE\'', '<p>‘CAUSE’</p>', false],
            ['\'til here', '<p>’til here</p>', false],
            ['\'til\'', '<p>‘til’</p>', false],
            ['\'TIL here', '<p>’TIL here</p>', false],
            ['\'TIL\'', '<p>‘TIL’</p>', false],
            ['\'n here', '<p>’n here</p>', false],
            ['\'n\'', '<p>‘n’</p>', false],
            ['\'N here', '<p>’N here</p>', false],
            ['\'N\'', '<p>‘N’</p>', false],
            ['\'bout here', '<p>’bout here</p>', false],
            ['\'bout\'', '<p>‘bout’</p>', false],
            ['\'BOUT here', '<p>’BOUT here</p>', false],
            ['\'BOUT\'', '<p>‘BOUT’</p>', false],
            ['\'tisn\'t \'twasn\'t', '<p>’tisn’t ’twasn’t</p>', false],
            ['\'em\'2', '<p>’em’2</p>', false],
            ['\'em\'é', '<p>’em’é</p>', false],
            ['\'tissue', '<p>‘tissue</p>', false],
            ['\'tisé', '<p>‘tisé</p>', false],
            ['\'one \'two\' end\'', '<p>‘one ’two’ end’</p>', false],
            ['\'one don\'t \'two\' end\'', '<p>‘one don’t ’two’ end’</p>', false],
            ['\'one \'70s \'two\' end\'', '<p>‘one ’70s ’two’ end’</p>', false],
            ['\'one é\'é \'two\' end\'', '<p>‘one é’é ’two’ end’</p>', false],
            ['\'one \'tis \'two\' end\'', '<p>‘one ’tis ’two’ end’</p>', false],
            ['say \'word', '<p>say ’word</p>', false],
            ['\'word', '<p>‘word</p>', false],
            ['say "\'word', '<p>say “‘word</p>', false],
            ['say \'*bold* text', '<p>say ‘<strong>bold</strong> text</p>', false],
            ['say \' word', '<p>say ‘ word</p>', false],
            ['\'*bold* \'inner\'', '<p>‘<strong>bold</strong> ’inner’</p>', false],
            ['say \'one\' \'two', '<p>say ‘one’ ’two</p>', false],
            ['say \'word \' end', '<p>say ’word ‘ end</p>', false],
            ['\'one *\'two* end\'', '<p>‘one <strong>’two</strong> end’</p>', false],
            ['say *x \'word*', '<p>say <strong>x ’word</strong></p>', false],
            ['\'one [x \'two](url) end\'', '<p>‘one <a href="url">x ’two</a> end’</p>', false],
            [
                '\'one

\'next\'', '<p>‘one</p>
<p>‘next’</p>', false,
            ],
            [
                '# \'one

\'next\'', '<p>‘next’</p>', true,
            ],
            [
                '- \'one
- \'next\'', '<li>‘next’</li>', true,
            ],
            [
                '| \'one | \'next\' |
|---|---|', '‘next’</th>', true,
            ],
        ];
        foreach ($cases as [$source, $expected, $contains]) {
            $actual = trim($converter->convert($source));
            if ($contains) {
                $this->assertStringContainsString($expected, $actual, $source);
            } else {
                $this->assertSame($expected, $actual, $source);
            }
        }
    }

    public function testInlineFootnoteQuoteScope(): void
    {
        $converter = new CarveConverter();
        $cases = [
            ["say ^['note] 'two'", ['</sup></a> ‘two’</p>', '<p>‘note<a href="#fnref1"']],
            ["say 'one ^[say 'note] 'two' end'", ['<p>say ‘one ', '</sup></a> ’two’ end’</p>', '<p>say ’note<a href="#fnref1"']],
            ["say 'one ^['note'] 'two' end'", ['</sup></a> ’two’ end’</p>', '<p>‘note’<a href="#fnref1"']],
            ["say 'one ^[note'] end", ['<p>say ’one ', '<p>note’<a href="#fnref1"']],
            ["say ^[say *x 'note*] ^['other] 'two'", ['<strong>x ’note</strong>', '<p>‘other<a href="#fnref2"', '</sup></a> ‘two’</p>']],
            ["say ^['one [x 'two] end'] 'three'", ['‘one [x ’two] end’', '</sup></a> ‘three’</p>']],
            ["say ^['one [x 'two](url) end'] 'three'", ['‘one <a href="url">x ’two</a> end’', '</sup></a> ‘three’</p>']],
        ];
        foreach ($cases as [$source, $fragments]) {
            $output = $converter->convert($source);
            foreach ($fragments as $fragment) {
                $this->assertStringContainsString($fragment, $output, $source);
            }
        }

        $converter->addExtension(new SmartQuotesExtension(locale: 'de'));
        $source = "say ^[say 'note] 'two'";
        $output = $converter->convert($source);
        $this->assertStringContainsString('<p>say ’note<a href="#fnref1"', $output);
        $this->assertStringContainsString('</sup></a> ‚two‘</p>', $output);
        $this->assertStringContainsString("say 'note", CarveConverter::toCarve($source));
        $document = (new CarveConverter())->parse($source);
        $note = $document->getChildren()[0]->getChildren()[1];
        $quote = $note->getChildren()[1];
        $this->assertInstanceOf(SmartPunctuation::class, $quote);
        $this->assertSame('right_single_quote', $quote->getKind());
    }

    public function testImageLabelScanLeavesParagraphQuotesUntouched(): void
    {
        $converter = new CarveConverter();
        foreach (["say !['note](url) 'two'", "say !['note][ref] 'two'\n\n[ref]: url"] as $source) {
            $this->assertStringContainsString('alt="&apos;note"> ‘two’</p>', $converter->convert($source));
        }
        $output = $converter->convert("say 'one ![note'](url) 'two' end'");
        $this->assertStringContainsString('<p>say ‘one ', $output);
        $this->assertStringContainsString('alt="note&apos;"> ’two’ end’</p>', $output);
    }

    public function testCitationMetadataHasItsOwnQuoteScope(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new CitationsExtension());
        $document = $converter->parse("say 'one [@a, p. 1 'note] end'");
        $children = $document->getChildren()[0]->getChildren();
        $this->assertInstanceOf(CitationGroup::class, $children[3]);
        $this->assertInstanceOf(SmartPunctuation::class, $children[1]);
        $this->assertInstanceOf(SmartPunctuation::class, $children[5]);
        $item = $children[3]->getItems()[0];
        $locatorQuote = $item['locator'][1] ?? null;
        $suffixQuote = $item['suffix'][0] ?? null;
        $this->assertInstanceOf(SmartPunctuation::class, $locatorQuote);
        $this->assertInstanceOf(SmartPunctuation::class, $suffixQuote);
        $this->assertSame('right_single_quote', $locatorQuote->getKind());
        $this->assertSame('left_single_quote', $suffixQuote->getKind());
        $this->assertSame('left_single_quote', $children[1]->getKind());
        $this->assertSame('right_single_quote', $children[5]->getKind());
    }

    public function testCommentsReadLastEmittedCharacter(): void
    {
        $converter = new CarveConverter();
        $cases = [
            ['x {% hidden %}"q"', '<p>x “q”</p>'],
            ["x {% hidden %}'q'", '<p>x ‘q’</p>'],
            ['a{%%}"q"', '<p>a”q”</p>'],
            ['[x]{.c}"q"', '<p><span class="c">x</span>”q”</p>'],
            ['\\{{%%}"q"', '<p>{“q”</p>'],
            ['{% c %}"q"', '<p>“q”</p>'],
            ['x---{% c %}" y', '<p>x—” y</p>'],
        ];
        foreach ($cases as [$source, $expected]) {
            $this->assertSame($expected, trim($converter->convert($source)), $source);
        }
    }

    public function testLocaleAndSourceKinds(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new SmartQuotesExtension(locale: 'de'));
        $this->assertSame('<p>say ’word and ’tis</p>', trim($converter->convert("say 'word and 'tis")));
        $this->assertSame('<p>‚word‘</p>', trim($converter->convert("'word'")));
        foreach (["say 'word", "'tis"] as $source) {
            $this->assertSame($source . "\n", CarveConverter::toCarve($source));
            $document = (new CarveConverter())->parse($source);
            $quote = $document->getChildren()[0]->getChildren()[($source === "'tis" ? 0 : 1)];
            $this->assertInstanceOf(SmartPunctuation::class, $quote);
            $this->assertSame('right_single_quote', $quote->getKind());
        }
    }

    public function testOpaqueContentAndDisabledTypography(): void
    {
        $converter = new CarveConverter();
        $cases = [
            ["\\'tis \\\"word", "<p>'tis \"word</p>"],
            ["`'tis` and `'word`{=html}", "<p><code>'tis</code> and 'word</p>"],
            ["[x](https://example.com/'tis)", '<p><a href="https://example.com/&apos;tis">x</a></p>'],
            ["[x]{title=\"'tis\"}", '<p><span title="&apos;tis">x</span></p>'],
        ];
        foreach ($cases as [$source, $expected]) {
            $this->assertSame($expected, trim($converter->convert($source)));
        }
        $literal = new CarveConverter(smartTypography: false);
        $this->assertSame("<p>say 'word and 'tis</p>", trim($literal->convert("say 'word and 'tis")));
    }

    public function testInvisibleAttributesKeepPreviousCharacter(): void
    {
        $converter = new CarveConverter();
        foreach (['{%%}', '{% comment %}', '{%%}{%%}'] as $invisible) {
            foreach (
                [
                    '\\{' . $invisible . '"q"' => '<p>{“q”</p>',
                    'word-' . $invisible . '" ' => '<p>word-”</p>',
                    'word—' . $invisible . "' " => '<p>word—’</p>',
                    'word-' . $invisible . '"q"' => '<p>word-“q”</p>',
                    '[x]{.c}' . $invisible . '"q"' => '<p><span class="c">x</span>”q”</p>',
                ] as $source => $expected
            ) {
                $this->assertSame($expected, trim($converter->convert($source)));
            }
        }
    }
}
