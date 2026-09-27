<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * A URL-list attribute is kept in the source and only hardened by the
 * renderer, so a denied token in it is not a dropped attribute
 * (markup-carve/carve-rs#2036).
 */
class AHardenedUrlListAttributeIsKeptTest extends TestCase
{
    public function testADeniedTokenDoesNotReportTheAttributeDropped(): void
    {
        $result = (new HtmlToCarve())->convertWithReport(
            '<p>see <img src="a.png" alt="a" srcset="a.png 1x, javascript:alert(1) 2x"> here</p>',
        );

        $this->assertSame('see ![a](a.png){srcset="a.png 1x, javascript:alert(1) 2x"} here', trim($result->value));
        $this->assertSame([], $result->diagnostics);
    }

    public function testTheAttributeNameInProseDoesNotAnswerForALoss(): void
    {
        $result = (new HtmlToCarve())->convertWithReport(
            '<p>text srcset=javascript:alert(1) text</p><section srcset="javascript:alert(1)"><h2>X</h2></section>',
        );

        $this->assertContains('Dropped unsupported attribute srcset on <section>', array_column($result->report()['diagnostics'], 'message'));
    }

    public function testADroppedCopyBeforeAKeptOneIsStillReported(): void
    {
        $result = (new HtmlToCarve())->convertWithReport(
            '<section srcset="javascript:x 1x"><h2>X</h2></section><p>see <img src="a.png" alt="" srcset="javascript:x 1x"></p>',
        );

        $messages = array_column($result->report()['diagnostics'], 'message');
        $this->assertContains('Dropped unsupported attribute srcset on <section>', $messages);
        $this->assertNotContains('Dropped unsupported attribute srcset on <img>', $messages);
    }

    public function testAnAttributeTheWriterDoesNotSpellIsReported(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<table><tbody ping="javascript:x"><tr><td>x</td></tr></tbody></table>');

        $this->assertContains('Dropped unsupported attribute ping on <tbody>', array_column($result->report()['diagnostics'], 'message'));
    }

    public function testOneKeptCopyAnswersForOneElement(): void
    {
        $result = (new HtmlToCarve())->convertWithReport(
            '<p><img src="a.png" alt="" srcset="javascript:x 1x"></p><section srcset="javascript:x 1x"><h2>X</h2></section>',
        );

        $this->assertContains('Dropped unsupported attribute srcset on <section>', array_column($result->report()['diagnostics'], 'message'));
    }
}
