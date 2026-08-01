<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\Frontmatter;
use MarkupCarve\Carve\Extension\FrontmatterExtension;
use PHPUnit\Framework\TestCase;

class FrontmatterExtensionTest extends TestCase
{
    public function testBasicYamlFrontmatter(): void
    {
        $ext = new FrontmatterExtension();
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $djot = <<<'DJOT'
---yaml
title: My Document
author: John Doe
---

# Hello World
DJOT;

        $html = $converter->convert($djot);

        // Frontmatter should not appear in output by default
        $this->assertStringNotContainsString('title:', $html);
        $this->assertStringNotContainsString('author:', $html);

        // Content should be rendered
        $this->assertStringContainsString('<section id="Hello-World">', $html);
        $this->assertStringContainsString('<h1>Hello World</h1>', $html);

        // Frontmatter should be accessible via extension
        $this->assertTrue($ext->hasFrontmatter());
        $this->assertNotNull($ext->getFrontmatter());
        $this->assertSame('yaml', $ext->getFormat());
        $this->assertStringContainsString('title: My Document', $ext->getContent());
        $this->assertStringContainsString('author: John Doe', $ext->getContent());
    }

    public function testTomlFrontmatter(): void
    {
        $ext = new FrontmatterExtension();
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $djot = <<<'DJOT'
---toml
title = "My Document"
date = 2024-01-15
---

Content here.
DJOT;

        $html = $converter->convert($djot);

        $this->assertSame('toml', $ext->getFormat());
        $this->assertStringContainsString('title = "My Document"', $ext->getContent());
    }

    public function testJsonFrontmatter(): void
    {
        $ext = new FrontmatterExtension();
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $djot = <<<'DJOT'
---json
{
  "title": "My Document",
  "tags": ["php", "djot"]
}
---

Content here.
DJOT;

        $html = $converter->convert($djot);

        $this->assertSame('json', $ext->getFormat());
        $this->assertStringContainsString('"title": "My Document"', $ext->getContent());
    }

    public function testNoFrontmatter(): void
    {
        $ext = new FrontmatterExtension();
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $djot = <<<'DJOT'
# Hello World

This document has no frontmatter.
DJOT;

        $html = $converter->convert($djot);

        $this->assertFalse($ext->hasFrontmatter());
        $this->assertNull($ext->getFrontmatter());
        $this->assertNull($ext->getFormat());
        $this->assertNull($ext->getContent());
    }

    public function testThematicBreakNotTreatedAsFrontmatter(): void
    {
        $ext = new FrontmatterExtension();
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $djot = <<<'DJOT'
---

# Hello World
DJOT;

        $html = $converter->convert($djot);

        // Should be rendered as thematic break, not frontmatter
        $this->assertFalse($ext->hasFrontmatter());
        $this->assertStringContainsString('<hr', $html);
    }

    public function testFrontmatterOnlyAtDocumentStart(): void
    {
        $ext = new FrontmatterExtension();
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $djot = <<<'DJOT'
# Hello World

---yaml
this: is not frontmatter
---

More content.
DJOT;

        $html = $converter->convert($djot);

        // Should not be parsed as frontmatter since it's not at the start
        $this->assertFalse($ext->hasFrontmatter());
    }

    public function testRenderAsComment(): void
    {
        $ext = new FrontmatterExtension(renderAsComment: true);
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $djot = <<<'DJOT'
---yaml
title: My Document
---

# Hello
DJOT;

        $html = $converter->convert($djot);

        // Frontmatter should appear as HTML comment
        $this->assertStringContainsString('<!-- frontmatter (yaml)', $html);
        $this->assertStringContainsString('title: My Document', $html);
        $this->assertStringContainsString('-->', $html);
    }

    public function testCustomRenderCallback(): void
    {
        $ext = new FrontmatterExtension(
            renderCallback: fn (Frontmatter $fm) => '<meta name="format" content="' . $fm->getFormat() . '">',
        );
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $djot = <<<'DJOT'
---yaml
title: Test
---

Content.
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringContainsString('<meta name="format" content="yaml">', $html);
    }

    public function testGetParsedContent(): void
    {
        $ext = new FrontmatterExtension();
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $djot = <<<'DJOT'
---json
{"title": "Test", "count": 42}
---

Content.
DJOT;

        $converter->convert($djot);

        $data = $ext->getParsedContent(function (string $content, string $format) {
            $this->assertSame('json', $format);

            return json_decode($content, true);
        });

        $this->assertIsArray($data);
        $this->assertSame('Test', $data['title']);
        $this->assertSame(42, $data['count']);
    }

    public function testGetParsedContentReturnsNullWithoutFrontmatter(): void
    {
        $ext = new FrontmatterExtension();
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $converter->convert('# No frontmatter');

        $result = $ext->getParsedContent(fn ($content, $format) => ['parsed' => true]);

        $this->assertNull($result);
    }

    public function testReset(): void
    {
        $ext = new FrontmatterExtension();
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $djot1 = <<<'DJOT'
---yaml
doc: first
---

First document.
DJOT;

        $converter->convert($djot1);
        $this->assertTrue($ext->hasFrontmatter());
        $this->assertStringContainsString('first', $ext->getContent());

        // Reset for new document
        $ext->reset();
        $this->assertFalse($ext->hasFrontmatter());

        // Parse second document
        $djot2 = <<<'DJOT'
---yaml
doc: second
---

Second document.
DJOT;

        $converter->convert($djot2);
        $this->assertTrue($ext->hasFrontmatter());
        $this->assertStringContainsString('second', $ext->getContent());
    }

    public function testMultilineFrontmatterContent(): void
    {
        $ext = new FrontmatterExtension();
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $djot = <<<'DJOT'
---yaml
title: My Document
description: |
  This is a multiline
  description that spans
  several lines.
tags:
  - php
  - djot
  - parser
---

# Content
DJOT;

        $converter->convert($djot);

        $this->assertTrue($ext->hasFrontmatter());
        $this->assertStringContainsString('multiline', $ext->getContent());
        $this->assertStringContainsString('- php', $ext->getContent());
    }

    public function testEmptyFrontmatter(): void
    {
        $ext = new FrontmatterExtension();
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $djot = <<<'DJOT'
---yaml
---

# Content
DJOT;

        $converter->convert($djot);

        $this->assertTrue($ext->hasFrontmatter());
        $this->assertSame('', $ext->getContent());
        $this->assertSame('yaml', $ext->getFormat());
    }

    public function testFrontmatterNodeType(): void
    {
        $ext = new FrontmatterExtension();
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $djot = <<<'DJOT'
---yaml
title: Test
---

Content.
DJOT;

        $converter->convert($djot);

        $frontmatter = $ext->getFrontmatter();
        $this->assertInstanceOf(Frontmatter::class, $frontmatter);
        $this->assertSame('frontmatter', $frontmatter->getType());
    }

    public function testFrontmatterWithBlockAttributes(): void
    {
        $ext = new FrontmatterExtension();
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $djot = <<<'DJOT'
{.meta}
---yaml
title: Test
---

Content.
DJOT;

        $converter->convert($djot);

        $this->assertTrue($ext->hasFrontmatter());
        $this->assertSame('yaml', $ext->getFormat());

        $frontmatter = $ext->getFrontmatter();
        $this->assertSame('meta', $frontmatter->getAttribute('class'));
    }

    public function testFrontmatterWithMultipleBlockAttributes(): void
    {
        $ext = new FrontmatterExtension();
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $djot = <<<'DJOT'
{kernel="myproject" #cell-1}
---python
import flight
---

Content.
DJOT;

        $converter->convert($djot);

        $this->assertTrue($ext->hasFrontmatter());
        $this->assertSame('python', $ext->getFormat());
        $this->assertStringContainsString('import flight', $ext->getContent());

        $frontmatter = $ext->getFrontmatter();
        $this->assertSame('myproject', $frontmatter->getAttribute('kernel'));
        $this->assertSame('cell-1', $frontmatter->getAttribute('id'));
    }

    public function testCommentEscapingInRenderAsComment(): void
    {
        $ext = new FrontmatterExtension(renderAsComment: true);
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $djot = <<<'DJOT'
---yaml
comment: "This has -- dashes"
---

Content.
DJOT;

        $html = $converter->convert($djot);

        // Double dashes should be escaped to prevent breaking HTML comment
        $this->assertStringContainsString('&#45;&#45;', $html);
        $this->assertStringNotContainsString('-- dashes', $html);
    }

    public function testUnclosedFrontmatterNotParsed(): void
    {
        $ext = new FrontmatterExtension();
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        // Frontmatter without closing ---
        $djot = <<<'DJOT'
---yaml
title: Missing Closer

# This should be content, not frontmatter
DJOT;

        $html = $converter->convert($djot);

        // Should NOT be parsed as frontmatter since closing --- is missing
        $this->assertFalse($ext->hasFrontmatter());

        // Content should appear in output (as paragraph since ---yaml line isn't valid block)
        $this->assertStringContainsString('title:', $html);
    }

    public function testExtensionReuseAutoClearsState(): void
    {
        $ext = new FrontmatterExtension();
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        // First document with frontmatter
        $djot1 = <<<'DJOT'
---yaml
doc: first
---

First document.
DJOT;

        $converter->convert($djot1);
        $this->assertTrue($ext->hasFrontmatter());
        $this->assertStringContainsString('first', $ext->getContent());

        // Second document WITHOUT frontmatter (no explicit reset)
        $djot2 = <<<'DJOT'
# Second Document

No frontmatter here.
DJOT;

        $converter->convert($djot2);

        // State should be automatically cleared - should NOT retain first document's frontmatter
        $this->assertFalse($ext->hasFrontmatter());
        $this->assertNull($ext->getContent());
    }

    public function testParseWithoutFrontmatterClearsPreviousState(): void
    {
        $ext = new FrontmatterExtension();
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $converter->parse(<<<'DJOT'
---yaml
doc: first
---

First document.
DJOT);
        $this->assertSame('doc: first', $ext->getContent());

        $converter->parse(<<<'DJOT'
# Second Document

No frontmatter here.
DJOT);

        $this->assertFalse($ext->hasFrontmatter());
        $this->assertNull($ext->getContent());
    }

    public function testExtensionReuseWithNewFrontmatter(): void
    {
        $ext = new FrontmatterExtension();
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        // First document
        $djot1 = <<<'DJOT'
---yaml
doc: first
---

First.
DJOT;

        $converter->convert($djot1);
        $this->assertStringContainsString('first', $ext->getContent());

        // Second document with different frontmatter (no explicit reset)
        $djot2 = <<<'DJOT'
---toml
doc = "second"
---

Second.
DJOT;

        $converter->convert($djot2);

        // Should have second document's frontmatter
        $this->assertTrue($ext->hasFrontmatter());
        $this->assertSame('toml', $ext->getFormat());
        $this->assertStringContainsString('second', $ext->getContent());
    }

    public function testDefaultFormatUsedWhenNoFormatSpecified(): void
    {
        $ext = new FrontmatterExtension();
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $djot = <<<'DJOT'
---
title: My Document
---

# Hello
DJOT;

        $converter->convert($djot);

        $this->assertTrue($ext->hasFrontmatter());
        $this->assertSame('yaml', $ext->getFormat());
        $this->assertStringContainsString('title: My Document', $ext->getContent());
    }

    public function testCustomDefaultFormatIsUsedForBareOpening(): void
    {
        $ext = new FrontmatterExtension(defaultFormat: 'toml');
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $djot = <<<'DJOT'
---
title = "My Document"
---

# Hello
DJOT;

        $converter->convert($djot);

        $this->assertTrue($ext->hasFrontmatter());
        $this->assertSame('toml', $ext->getFormat());
        // Content is captured raw — straight quotes are NOT mangled into smart quotes.
        $this->assertStringContainsString('title = "My Document"', $ext->getContent());
    }

    public function testBareFrontmatterContentIsCapturedVerbatim(): void
    {
        // Regression: bare --- frontmatter is captured raw at the block level,
        // never routed through inline smart typography. Straight quotes, triple
        // dashes, and ellipses in the content must survive byte-for-byte so a
        // downstream YAML/TOML parser sees exactly what the author wrote.
        $ext = new FrontmatterExtension();
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $djot = <<<'DJOT'
---
quote: "straight"
range: 1---9
note: wait...
---

# Hello
DJOT;

        $converter->convert($djot);

        $content = (string)$ext->getContent();
        $this->assertSame("quote: \"straight\"\nrange: 1---9\nnote: wait...", $content);
        $this->assertStringNotContainsString("\u{201C}", $content);
        $this->assertStringNotContainsString("\u{2014}", $content);
        $this->assertStringNotContainsString("\u{2026}", $content);
    }

    public function testExplicitFormatOverridesDefaultFormat(): void
    {
        // An explicit format on the delimiter always takes precedence over defaultFormat
        $ext = new FrontmatterExtension(defaultFormat: 'toml');
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $djot = <<<'DJOT'
---json
{"title": "My Document"}
---

# Hello
DJOT;

        $converter->convert($djot);

        $this->assertTrue($ext->hasFrontmatter());
        $this->assertSame('json', $ext->getFormat());
    }

    public function testLenientSpaceBeforeFormatToken(): void
    {
        // `--- toml` (space) is accepted as well as `---toml`; the no-space form
        // is canonical. Matches the optional space on a code fence.
        $ext = new FrontmatterExtension();
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $djot = <<<'DJOT'
--- toml
title = "My Document"
---

Content here.
DJOT;

        $html = $converter->convert($djot);

        $this->assertStringNotContainsString('title =', $html);
        $this->assertTrue($ext->hasFrontmatter());
        $this->assertSame('toml', $ext->getFormat());
        $this->assertStringContainsString('title = "My Document"', $ext->getContent());
    }

    public function testIsNotClaimedAfterALeadingBlankLine(): void
    {
        // grammar.ebnf: `document = [frontmatter], {block}, EOF` - frontmatter
        // is the FIRST production, so it must begin at the very first line. A
        // leading blank means the document already opened with a block, and the
        // `---` pair below is two thematic breaks. Matching only "first child
        // of Document" let a blank-prefixed pair swallow the whole document.
        $converter = new CarveConverter();
        $converter->addExtension(new FrontmatterExtension());

        $this->assertSame("<hr>\n<hr>\n", $converter->convert("\n---\n\n---\n"));
        $this->assertSame("<hr>\n<hr>\n", $converter->convert("\n---\n---\n"));
    }

    public function testIsNotClaimedAfterALeadingBlankEvenWithRealMetadata(): void
    {
        $ext = new FrontmatterExtension();
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $html = $converter->convert("\n---\ntitle: X\n---\n\nBody\n");

        $this->assertFalse($ext->hasFrontmatter());
        $this->assertStringContainsString('title: X', $html);
    }

    public function testIsStillClaimedOnTheVeryFirstLine(): void
    {
        $ext = new FrontmatterExtension();
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        $html = $converter->convert("---\ntitle: X\n---\n\nBody\n");

        $this->assertTrue($ext->hasFrontmatter());
        $this->assertStringNotContainsString('title: X', $html);
        $this->assertSame("<p>Body</p>\n", $html);
    }
}
