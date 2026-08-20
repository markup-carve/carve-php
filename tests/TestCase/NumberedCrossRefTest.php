<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class NumberedCrossRefTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testFigureCaptionNumber(): void
    {
        $html = $this->converter->convert(<<<'DJOT'
{#fig-sun}
![A sunset](sun.jpg)
^ Figure #: A sunset
DJOT);

        $this->assertStringContainsString('<figure id="fig-sun">', $html);
        $this->assertStringContainsString('<figcaption>Figure 1: A sunset</figcaption>', $html);
    }

    public function testFigureCountersPerLabel(): void
    {
        $html = $this->converter->convert(<<<'DJOT'
![One](one.jpg)
^ Figure #: One

![Two](two.jpg)
^ Figure #: Two
DJOT);

        $this->assertStringContainsString('<figcaption>Figure 1: One</figcaption>', $html);
        $this->assertStringContainsString('<figcaption>Figure 2: Two</figcaption>', $html);
    }

    public function testFigureCrossReferenceUsesNumberedAutoText(): void
    {
        $html = $this->converter->convert(<<<'DJOT'
{#fig-sun}
![A sunset](sun.jpg)
^ Figure #: A sunset

See </#fig-sun> for the colors.
DJOT);

        $this->assertStringContainsString('<p>See <a href="#fig-sun">Figure 1</a> for the colors.</p>', $html);
    }

    public function testTableCrossReferenceUsesNumberedAutoText(): void
    {
        $html = $this->converter->convert(<<<'DJOT'
{#tbl-r}
| Item | Qty |
| ---- | --- |
| Red  | 3   |
^ Table #: Stock

See </#tbl-r>.
DJOT);

        $this->assertStringContainsString('<caption>Table 1: Stock</caption>', $html);
        $this->assertStringContainsString('<p>See <a href="#tbl-r">Table 1</a>.</p>', $html);
    }

    public function testCountersAreSeparatePerLabel(): void
    {
        $html = $this->converter->convert(<<<'DJOT'
![Erstes](eins.jpg)
^ Abbildung #: erstes

![First](one.jpg)
^ Figure #: first
DJOT);

        $this->assertStringContainsString('<figcaption>Abbildung 1: erstes</figcaption>', $html);
        $this->assertStringContainsString('<figcaption>Figure 1: first</figcaption>', $html);
    }

    public function testHashFollowedByLetterStaysTag(): void
    {
        $html = $this->converter->convert(<<<'DJOT'
![Data](data.jpg)
^ See #data for details
DJOT);

        $this->assertStringContainsString(
            '<figcaption>See <span class="tag"><strong>#data</strong></span> for details</figcaption>',
            $html,
        );
    }

    public function testEscapedHashIsLiteral(): void
    {
        $html = $this->converter->convert(<<<'DJOT'
![Costs](costs.jpg)
^ Costs \# units
DJOT);

        $this->assertStringContainsString('<figcaption>Costs # units</figcaption>', $html);
    }

    public function testSecondCrossReferenceReusesNumber(): void
    {
        $html = $this->converter->convert(<<<'DJOT'
{#fig-sun}
![A sunset](sun.jpg)
^ Figure #: A sunset

See </#fig-sun> and </#fig-sun>.
DJOT);

        $this->assertStringContainsString(
            '<p>See <a href="#fig-sun">Figure 1</a> and <a href="#fig-sun">Figure 1</a>.</p>',
            $html,
        );
    }

    public function testOnlyFirstBareHashBecomesNumber(): void
    {
        $html = $this->converter->convert(<<<'DJOT'
![Many](many.jpg)
^ Figure # and # marker
DJOT);

        $this->assertStringContainsString('<figcaption>Figure 1 and # marker</figcaption>', $html);
    }

    public function testListingCaptionWrapsCodeBlockInFigure(): void
    {
        $html = $this->converter->convert(<<<'DJOT'
```python
x = 1
```
^ Listing 1: example
DJOT);

        $this->assertStringContainsString('<figure>', $html);
        $this->assertStringContainsString(
            '<pre><code class="language-python">x = 1' . "\n" . '</code></pre>',
            $html,
        );
        $this->assertStringContainsString('<figcaption>Listing 1: example</figcaption>', $html);
    }

    public function testCodeBlockWithoutCaptionIsNotWrapped(): void
    {
        $html = $this->converter->convert(<<<'DJOT'
```python
x = 1
```
DJOT);

        $this->assertStringNotContainsString('<figure>', $html);
    }

    public function testListingNumberAndCrossReference(): void
    {
        $html = $this->converter->convert(<<<'DJOT'
{#lst-a}
```python
x = 1
```
^ Listing #: example

See </#lst-a>.
DJOT);

        $this->assertStringContainsString('<figcaption>Listing 1: example</figcaption>', $html);
        $this->assertStringContainsString('See <a href="#lst-a">Listing 1</a>.', $html);
    }

    public function testEquationCaptionWrapsDisplayMathInFigure(): void
    {
        $html = $this->converter->convert(<<<'DJOT'
$$`E = mc^2`
^ Equation 1: mass-energy
DJOT);

        $this->assertStringContainsString('<figure>', $html);
        $this->assertStringContainsString('<span class="math display" role="math">\[E = mc^2\]</span>', $html);
        $this->assertStringContainsString('<figcaption>Equation 1: mass-energy</figcaption>', $html);
    }

    public function testDisplayMathWithoutCaptionIsNotWrapped(): void
    {
        $html = $this->converter->convert(<<<'DJOT'
$$`E = mc^2`
DJOT);

        $this->assertStringNotContainsString('<figure>', $html);
        $this->assertStringContainsString('<span class="math display" role="math">\[E = mc^2\]</span>', $html);
    }

    public function testInlineMathIsNotWrapped(): void
    {
        $html = $this->converter->convert(<<<'DJOT'
Energy is $`E=mc^2` here.
^ Equation #: x
DJOT);

        $this->assertStringNotContainsString('<figure>', $html);
    }

    public function testEquationNumberAndCrossReference(): void
    {
        $html = $this->converter->convert(<<<'DJOT'
{#eq-e}
$$`E = mc^2`
^ Equation #: mass-energy

See </#eq-e>.
DJOT);

        $this->assertStringContainsString('<figure id="eq-e">', $html);
        $this->assertStringContainsString('See <a href="#eq-e">Equation 1</a>.', $html);
    }

    public function testEquationCounterPerLabel(): void
    {
        $html = $this->converter->convert(<<<'DJOT'
$$`E = mc^2`
^ Equation #: one

$$`a + b`
^ Equation #: two
DJOT);

        $this->assertStringContainsString('Equation 1: one', $html);
        $this->assertStringContainsString('Equation 2: two', $html);
    }
}
