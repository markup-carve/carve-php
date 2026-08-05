<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Filter;

use MarkupCarve\Carve\Exception\ProfileViolationException;
use MarkupCarve\Carve\Extension\Frontmatter;
use MarkupCarve\Carve\LinkPolicy;
use MarkupCarve\Carve\Node\Block\AbbreviationDefinition;
use MarkupCarve\Carve\Node\Block\BlockNode;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\DefinitionDescription;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\DefinitionTerm;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\LinkReferenceDefinition;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Block\ThematicBreak;
use MarkupCarve\Carve\Node\ContentNodeInterface;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\CitationGroup;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\InlineNode;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Substitution;
use MarkupCarve\Carve\Node\Inline\Symbol;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Profile;
use MarkupCarve\Carve\ProfileViolation;

/**
 * Filters a document AST according to a Profile
 *
 * Walks the document tree and either strips, converts to text,
 * or throws exceptions for disallowed nodes.
 */
class ProfileFilter
{
    /**
     * @var int
     */
    private const MAX_FILTER_DEPTH = 512;

    /**
     * @var list<\MarkupCarve\Carve\ProfileViolation>
     */
    protected array $violations = [];

    /**
     * Parents this filter actually removed a child from, keyed by object id.
     *
     * Empty-container cleanup is scoped to these. A blanket pass over the whole
     * document also removed containers that were empty in the SOURCE, so a
     * profile denying nothing still changed the output - and an empty container
     * is not always meaningless. `::: footnotes` is empty BY DEFINITION: its
     * emptiness is the syntax for "put the endnotes here" (carve-php#505).
     *
     * @var array<int, \MarkupCarve\Carve\Node\Node>
     */
    protected array $emptied = [];

    protected ?string $baseHost = null;

    /**
     * Set the base host for external link detection
     */
    public function setBaseHost(?string $host): void
    {
        $this->baseHost = $host;
    }

    /**
     * Filter a document according to the profile
     */
    public function filter(Document $doc, Profile $profile): Document
    {
        $this->violations = [];
        $this->emptied = [];
        $this->filterChildren($doc, $profile, 0);
        $this->cleanupEmptyContainers($doc, 0);
        $this->resolveFootnoteRefs($doc);

        return $doc;
    }

    /**
     * @return list<\MarkupCarve\Carve\ProfileViolation>
     */
    public function getViolations(): array
    {
        return $this->violations;
    }

    public function clearViolations(): void
    {
        $this->violations = [];
    }

    protected function filterChildren(Node $parent, Profile $profile, int $depth): void
    {
        if ($depth >= self::MAX_FILTER_DEPTH) {
            return;
        }

        // Get a copy of children since we may modify during iteration
        $children = $parent->getChildren();

        foreach ($children as $child) {
            // Check nesting depth
            $maxNesting = $profile->getMaxNesting();
            if ($maxNesting > 0 && $depth > $maxNesting) {
                $this->handleViolation($child, $parent, $profile, 'max_nesting_exceeded');

                continue;
            }

            // Check if this node type is allowed
            if (!$profile->isNodeAllowed($child)) {
                $this->handleViolation($child, $parent, $profile, 'element_not_allowed');

                continue;
            }

            // Special handling for links
            if ($child instanceof Link && $profile->getLinkPolicy() !== null) {
                $this->filterLink($child, $parent, $profile);
            }

            // Special handling for images
            if ($child instanceof Image && $profile->getLinkPolicy() !== null) {
                $this->filterImage($child, $parent, $profile);
            }

            // Recursively filter children
            $this->filterChildren($child, $profile, $depth + 1);
        }
    }

    protected function filterLink(Link $node, Node $parent, Profile $profile): void
    {
        $policy = $profile->getLinkPolicy();
        if ($policy === null) {
            return;
        }

        $url = $node->getDestination() ?? '';

        if (!$policy->isUrlAllowed($url, $this->baseHost)) {
            $this->handleViolation($node, $parent, $profile, 'link_not_allowed');

            return;
        }

        // Add rel attributes if configured
        $this->applyRelAttributes($node, $policy);
    }

    protected function filterImage(Image $node, Node $parent, Profile $profile): void
    {
        $policy = $profile->getLinkPolicy();
        if ($policy === null) {
            return;
        }

        $url = $node->getSource();

        if (!$policy->isUrlAllowed($url, $this->baseHost)) {
            $this->handleViolation($node, $parent, $profile, 'image_not_allowed');
        }
    }

    protected function applyRelAttributes(Link $node, LinkPolicy $policy): void
    {
        $relAttrs = $policy->getRelAttributes();
        if ($relAttrs === []) {
            return;
        }

        $existing = $node->getAttribute('rel');
        $existingArray = $existing !== null ? explode(' ', (string)$existing) : [];

        foreach ($relAttrs as $rel) {
            if (!in_array($rel, $existingArray, true)) {
                $existingArray[] = $rel;
            }
        }

        $node->setAttribute('rel', implode(' ', $existingArray));
    }

    protected function handleViolation(Node $node, Node $parent, Profile $profile, string $reason): void
    {
        // The canonical name, not getType(): the allow check tested `autolink`
        // and `admonition`, so reporting the broader `link` / `div` would name a
        // different decision than the one that was made.
        $type = Profile::canonicalTypeOf($node);
        $reasonDescription = $profile->getReasonDisallowed($type);

        $this->violations[] = new ProfileViolation(
            $type,
            $reason,
            $reasonDescription,
        );

        match ($profile->getDisallowedAction()) {
            Profile::ACTION_STRIP => $this->stripNode($node, $parent),
            Profile::ACTION_TO_TEXT => $this->convertToText($node, $parent),
            Profile::ACTION_ERROR => throw new ProfileViolationException($this->violations),
            default => $this->convertToText($node, $parent),
        };
    }

    protected function stripNode(Node $node, Node $parent): void
    {
        $this->emptied[spl_object_id($parent)] = $parent;
        $parent->removeChild($node);
    }

    protected function convertToText(Node $node, Node $parent): void
    {
        // Some nodes never render where they are written. Converting them to
        // text would inject source that the unrestricted render path suppresses.
        if ($this->rendersNothing($node)) {
            $this->emptied[spl_object_id($parent)] = $parent;
            $parent->removeChild($node);

            return;
        }

        $textContent = $this->extractTextContent($node, 0);

        if ($textContent === '') {
            // extractTextContent has no arm for the node's payload: record it
            // rather than deleting visible content, and substitute a marker
            // deliberately ugly enough that it cannot pass for intended output.
            $type = Profile::canonicalTypeOf($node);
            $this->violations[] = new ProfileViolation($type, 'to_text_yielded_nothing', null);
            $textContent = '[' . $type . ']';
        }

        // Decided by POSITION, not by class. A lone image is a block-level
        // image node (#633) while `Image` extends `InlineNode`, so a class test
        // put its placeholder text straight into a container as a bare text
        // node - block content that is not in any block. Everything else keeps
        // today's behavior: a block node's parent is a container, an inline
        // node's parent is a paragraph or another inline.
        $inBlockPosition = $node instanceof BlockNode
            || (!$parent instanceof Paragraph && !$parent instanceof InlineNode);
        if ($inBlockPosition) {
            $paragraph = new Paragraph();
            $this->appendTextWithBreaks($paragraph, $textContent);
            $parent->replaceChildNode($node, $paragraph);
        } else {
            // Inline node - replace with text
            $textNode = new Text($textContent);
            $parent->replaceChildNode($node, $textNode);
        }
    }

    /**
     * Whether a node contributes no visible output of its own.
     *
     * Only such a node may be removed outright by `to_text`; for anything else
     * removal would be data loss wearing the name of a downgrade.
     */
    protected function rendersNothing(Node $node): bool
    {
        // Deliberately not a list of "probably empty" node types: a
        // ThematicBreak already extracts to `---` and never reaches here, so
        // adding it would be a branch that cannot fire.
        //
        // A comment produces no output by definition. Frontmatter is document
        // METADATA - it is published on the serialized root rather than in the
        // document's flow (PART 12 section 2) and renders nowhere - so degrading
        // it to text has nothing to substitute. Without this it took the
        // missing-extractor path and injected a literal `[frontmatter]`
        // paragraph into the output, which is the marker's way of saying an arm
        // is missing rather than a claim about the content.
        // A definition line is the same case again, and profiles.md names the
        // two together: "`link_reference_definition` is the `abbreviation_def`
        // case exactly: the definition line renders nothing in HTML, so denying
        // it would not stop anything reaching the page - the `link` or `image`
        // it feeds is the node a profile denies." Without them, denying a link
        // reference definition under the default action injected a literal
        // `[link_reference_definition]` paragraph, the same marker frontmatter
        // used to produce (carve-php#855). The `link` and the `abbr` they feed
        // are separate nodes a profile denies separately, and still render.
        return $node instanceof Comment
            || $node instanceof Frontmatter
            || $node instanceof Footnote
            || $node instanceof AbbreviationDefinition
            || $node instanceof LinkReferenceDefinition;
    }

    protected function resolveFootnoteRefs(Document $document): void
    {
        $defined = [];
        foreach ($document->getChildren() as $child) {
            if ($child instanceof Footnote) {
                $defined[$child->getLabel()] = true;
            }
        }

        $walk = static function (Node $node, int $depth) use (&$walk, $defined): void {
            if ($depth >= self::MAX_FILTER_DEPTH) {
                return;
            }

            if ($node instanceof FootnoteRef) {
                $unresolved = !isset($defined[$node->getLabel()]);
                $node->setUnresolved($unresolved);
                if ($unresolved) {
                    $node->setNumber(null);
                }
            }
            foreach ($node->getChildren() as $child) {
                $walk($child, $depth + 1);
            }
        };
        $walk($document, 0);
    }

    /**
     * Append text content to a node, converting newlines to HardBreak nodes
     */
    protected function appendTextWithBreaks(Node $parent, string $content): void
    {
        $lines = explode("\n", $content);
        $lastIndex = count($lines) - 1;

        foreach ($lines as $index => $line) {
            if ($line !== '') {
                $parent->appendChild(new Text($line));
            }
            // Add line break between lines (not after the last line)
            if ($index < $lastIndex) {
                $parent->appendChild(new HardBreak());
            }
        }
    }

    /**
     * Remove empty container nodes (list items, paragraphs with no content, empty lists)
     */
    protected function cleanupEmptyContainers(Node $parent, int $depth): void
    {
        if ($depth >= self::MAX_FILTER_DEPTH) {
            return;
        }

        $children = $parent->getChildren();

        foreach ($children as $child) {
            // Recursively clean up children first
            $this->cleanupEmptyContainers($child, $depth + 1);

            // Only prune what THIS filter emptied. A container that arrived
            // empty is the author's, and reproducing it is what makes a profile
            // that denies nothing an identity (carve-php#506).
            if (!isset($this->emptied[spl_object_id($child)])) {
                continue;
            }

            // Check if this node is now empty and should be removed
            if ($this->isEmptyContainer($child, $depth + 1)) {
                $parent->removeChild($child);
                // Its own parent has now lost a child too, so an emptied
                // wrapper cascades up exactly as the blanket pass did.
                $this->emptied[spl_object_id($parent)] = $parent;
            }
        }
    }

    /**
     * Check if a node is an empty container that should be removed
     */
    protected function isEmptyContainer(Node $node, int $depth): bool
    {
        if ($depth >= self::MAX_FILTER_DEPTH) {
            return false;
        }

        // Text nodes are empty if they have no content
        if ($node instanceof Text) {
            return $node->getContent() === '';
        }

        // Nodes that store raw content directly are not empty if they have content.
        if ($node instanceof ContentNodeInterface) {
            $content = $node->getContent();
            if ($content !== '') {
                return false;
            }
        }

        // Check if all children are empty
        $children = $node->getChildren();
        if ($children === []) {
            // Structural elements that must be preserved even when empty:
            // - ThematicBreak: valid self-closing element (renders as <hr>)
            // - TableCell: maintains table column structure
            // - Div placement carrier (`::: footnotes` / `::: toc`): marks
            //   WHERE the renderer relocates content (the endnotes section, the
            //   heading nav), so pruning it as "empty" silently moves that
            //   content back to its un-relocated default position.
            //   Still load-bearing after cleanup was scoped to emptied parents,
            //   and the two cover DIFFERENT cases: a CHILDLESS carrier is never
            //   marked, so the scoping alone protects it, but one whose body the
            //   filter strips IS marked and reaches here. Removing this arm
            //   loses the placement for exactly that document (pinned in
            //   Filter/EmptyContainerCleanupTest).
            if (
                $node instanceof ThematicBreak
                || $node instanceof TableCell
                || ($node instanceof Div && $node->isPlacementCarrier())
            ) {
                return false;
            }

            return $node instanceof BlockNode;
        }

        // If all children are empty, this container is empty
        foreach ($children as $child) {
            if (!$this->isEmptyContainer($child, $depth + 1)) {
                return false;
            }
        }

        return true;
    }

    protected function extractTextContent(Node $node, int $depth): string
    {
        if ($depth >= self::MAX_FILTER_DEPTH) {
            return '';
        }

        // Special handling for images - show as [img: alt] or [img]
        if ($node instanceof Image) {
            $alt = $node->getAlt();

            return $alt !== '' ? '[img: ' . $alt . ']' : '[img]';
        }

        // Special handling for headings - preserve level with # prefix
        if ($node instanceof Heading) {
            $prefix = str_repeat('#', $node->getLevel()) . ' ';
            $text = '';
            foreach ($node->getChildren() as $child) {
                $text .= $this->extractTextContent($child, $depth + 1);
            }

            return $prefix . $text;
        }

        // Special handling for code blocks - wrap in backticks
        if ($node instanceof CodeBlock) {
            $content = $node->getContent();
            // Use single backticks for single-line, triple for multi-line
            if (str_contains($content, "\n")) {
                return "```\n" . $content . "\n```";
            }

            return '`' . $content . '`';
        }

        // Special handling for links - get child text content
        if ($node instanceof Link) {
            $text = '';
            foreach ($node->getChildren() as $child) {
                $text .= $this->extractTextContent($child, $depth + 1);
            }

            return $text;
        }

        // Special handling for tables - preserve row structure
        if ($node instanceof Table) {
            $rows = [];
            foreach ($node->getChildren() as $row) {
                if ($row instanceof TableRow) {
                    $cells = [];
                    foreach ($row->getChildren() as $cell) {
                        $cells[] = $this->extractTextContent($cell, $depth + 1);
                    }
                    $rows[] = implode(' | ', $cells);
                }
            }

            return implode("\n", $rows);
        }

        // Special handling for blockquotes - add > prefix
        if ($node instanceof BlockQuote) {
            $paragraphs = [];
            foreach ($node->getChildren() as $child) {
                $text = $this->extractTextContent($child, $depth + 1);
                if ($text !== '') {
                    $paragraphs[] = '> ' . $text;
                }
            }

            return implode("\n", $paragraphs);
        }

        // Special handling for definition lists - preserve term/description structure
        if ($node instanceof DefinitionList) {
            $parts = [];
            foreach ($node->getChildren() as $child) {
                $text = $this->extractTextContent($child, $depth + 1);
                if ($text !== '') {
                    // Add prefix for terms to distinguish from descriptions
                    if ($child instanceof DefinitionTerm) {
                        $parts[] = $text;
                    } elseif ($child instanceof DefinitionDescription) {
                        // Prefix descriptions with dash for visibility in HTML
                        $parts[] = '- ' . $text;
                    } else {
                        $parts[] = $text;
                    }
                }
            }

            return implode("\n", $parts);
        }

        // Special handling for lists - preserve item structure with markers
        if ($node instanceof ListBlock) {
            $items = [];
            $index = $node->getStart();
            foreach ($node->getChildren() as $child) {
                if ($child instanceof ListItem) {
                    $text = $this->extractTextContent($child, $depth + 1);
                    if ($text !== '') {
                        // Use appropriate marker based on list type
                        $marker = match ($node->getListType()) {
                            ListBlock::TYPE_ORDERED => $index . '. ',
                            default => '- ',
                        };
                        $items[] = $marker . $text;
                        $index++;
                    }
                }
            }

            return implode("\n", $items);
        }

        // A substitution keeps both texts in fields, not children. Dropping to
        // the generic child walk below would return '' and delete the node,
        // losing the old wording AND the new one.
        if ($node instanceof Substitution) {
            return $node->getOldText() . $node->getNewText();
        }

        // A citation group likewise renders from its items; `raw` is the
        // author's source form, which is the closest honest degradation.
        if ($node instanceof CitationGroup) {
            return $node->getRaw();
        }

        // Special handling for symbols - use the symbol name
        if ($node instanceof Symbol) {
            return ':' . $node->getName() . ':';
        }

        // Special handling for footnote references - use the label
        if ($node instanceof FootnoteRef) {
            return '[^' . $node->getLabel() . ']';
        }

        // Special handling for footnote definitions - preserve label with content
        if ($node instanceof Footnote) {
            $content = [];
            foreach ($node->getChildren() as $child) {
                $text = $this->extractTextContent($child, $depth + 1);
                if ($text !== '') {
                    $content[] = $text;
                }
            }

            return '[^' . $node->getLabel() . ']: ' . implode(' ', $content);
        }

        // Special handling for thematic breaks - show original marker
        if ($node instanceof ThematicBreak) {
            return '---';
        }

        if ($node instanceof Text) {
            return $node->getContent();
        }

        // Handle nodes that store content directly (CodeBlock, RawBlock, etc.)
        if ($node instanceof ContentNodeInterface) {
            $content = $node->getContent();
            if ($content !== '') {
                return $content;
            }
        }

        $parts = [];
        foreach ($node->getChildren() as $child) {
            $childText = $this->extractTextContent($child, $depth + 1);
            if ($childText !== '') {
                $parts[] = $childText;
            }
        }

        // Join with space for block elements to prevent text from running together
        return implode(' ', $parts);
    }
}
