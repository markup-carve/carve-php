<?php

declare(strict_types=1);

namespace MarkupCarve\Carve;

use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Node;

/**
 * Profile-based feature restriction for different rendering contexts
 *
 * Profiles complement SafeMode (XSS prevention) by controlling which
 * markup features are available. Use this to create different rendering
 * contexts like full documents, blog posts, user comments, or chat messages.
 */
class Profile
{
    /**
     * Strip disallowed elements from output
     *
     * @var string
     */
    public const ACTION_STRIP = 'strip';

    /**
     * Convert disallowed elements to plain text (default, safest for UX)
     *
     * @var string
     */
    public const ACTION_TO_TEXT = 'to_text';

    /**
     * Throw exception on disallowed elements
     *
     * @var string
     */
    public const ACTION_ERROR = 'error';

    /**
     * Default maximum input length (UTF-8 bytes) for the untrusted `comment`
     * preset - a DoS backstop enforced pre-parse. Generous for a comment body;
     * override with setMaxLength(0) to disable or another value to retune.
     *
     * @var int
     */
    public const COMMENT_MAX_LENGTH = 100000;

    /**
     * Default maximum input length (UTF-8 bytes) for the untrusted `minimal`
     * preset (chat / micro-posts). Override with setMaxLength() as needed.
     *
     * @var int
     */
    public const MINIMAL_MAX_LENGTH = 10000;

    protected string $name = 'custom';

    protected string $description = '';

    /**
     * @var array<string, string>
     */
    protected array $featureReasons = [];

    /**
     * @var list<string>|null
     */
    protected ?array $allowedInline = null;

    /**
     * @var list<string>|null
     */
    protected ?array $allowedBlock = null;

    /**
     * @var list<string>
     */
    protected array $deniedInline = [];

    /**
     * @var list<string>
     */
    protected array $deniedBlock = [];

    protected ?LinkPolicy $linkPolicy = null;

    protected int $maxNesting = 0;

    protected int $maxLength = 0;

    protected string $disallowedAction = self::ACTION_TO_TEXT;

    /**
     * Create a full profile with all features enabled
     *
     * Use for trusted content like backend documentation or admin interfaces.
     */
    public static function full(): self
    {
        $profile = new self();
        $profile->name = 'full';
        $profile->description = 'All features enabled. Use only for trusted content.';

        return $profile;
    }

    /**
     * Create an article profile suitable for blog posts and articles
     *
     * Disables raw HTML to prevent XSS while allowing all formatting features.
     * Authors can use all djot features except embedding raw HTML/JS.
     */
    public static function article(): self
    {
        $profile = new self();
        $profile->name = 'article';
        $profile->description = 'Blog posts and articles. All formatting, no raw HTML.';
        $profile
            ->denyBlock([NodeType::RAW_BLOCK])
            ->denyInline([NodeType::RAW_INLINE]);

        $profile->featureReasons = [
            NodeType::RAW_BLOCK => 'Raw HTML blocks are disabled to prevent XSS attacks. Use djot markup instead.',
            NodeType::RAW_INLINE => 'Raw HTML is disabled to prevent XSS attacks. Use djot markup instead.',
        ];

        return $profile;
    }

    /**
     * Create a comment profile suitable for user-generated content
     *
     * Allowed formatting:
     * - Inline: bold, italic, strikethrough, insert, highlight, superscript, subscript, code, links
     * - Block: paragraphs, lists, blockquotes, code blocks
     *
     * This prevents:
     * - Headings: Users shouldn't structure page hierarchy
     * - Images: Prevents spam, inappropriate content, bandwidth abuse
     * - Tables: Too complex for comments, often misused for layout
     * - Footnotes: Overkill for comments
     * - Raw HTML: XSS prevention
     * - Divs/Sections: Layout control not needed
     *
     * Links have nofollow/ugc attributes to prevent SEO spam.
     */
    public static function comment(): self
    {
        $profile = new self();
        $profile->name = 'comment';
        $profile->description = 'User comments. Basic formatting only, nofollow links.';
        $profile
            ->allowInline([
                NodeType::TEXT,
                NodeType::EMPHASIS,
                NodeType::STRONG,
                NodeType::UNDERLINE,
                NodeType::STRIKE,
                NodeType::INLINE_EXTENSION,
                NodeType::MENTION,
                NodeType::CODE,
                NodeType::LINK,
                NodeType::SOFT_BREAK,
                NodeType::HARD_BREAK,
                NodeType::DELETE,
                NodeType::INSERT,
                NodeType::HIGHLIGHT,
                NodeType::SUPERSCRIPT,
                NodeType::SUBSCRIPT,
            ])
            ->allowBlock([
                NodeType::PARAGRAPH,
                NodeType::LIST_BLOCK,
                NodeType::LIST_ITEM,
                NodeType::BLOCKQUOTE,
                NodeType::CODE_BLOCK,
            ])
            ->setLinkPolicy(
                LinkPolicy::unrestricted()
                    ->addRelAttribute('nofollow')
                    ->addRelAttribute('ugc'),
            )
            ->setMaxNesting(4)
            ->setMaxLength(self::COMMENT_MAX_LENGTH);

        $profile->featureReasons = [
            NodeType::HEADING => 'Headings are disabled in comments to prevent disrupting page structure.',
            NodeType::IMAGE => 'Images are disabled to prevent spam, inappropriate content, and bandwidth abuse.',
            NodeType::TABLE => 'Tables are disabled as they are too complex for comment formatting.',
            NodeType::FOOTNOTE => 'Footnotes are disabled as they are unnecessary for comments.',
            NodeType::FOOTNOTE_REF => 'Footnotes are disabled as they are unnecessary for comments.',
            NodeType::INLINE_FOOTNOTE => 'Footnotes are disabled as they are unnecessary for comments.',
            NodeType::RAW_BLOCK => 'Raw HTML is disabled for security reasons.',
            NodeType::RAW_INLINE => 'Raw HTML is disabled for security reasons.',
            NodeType::DIV => 'Custom containers are disabled in comments.',
            NodeType::SECTION => 'Sections are disabled in comments.',
            NodeType::DEFINITION_LIST => 'Definition lists are disabled in comments.',
            NodeType::DEFINITION_TERM => 'Definition lists are disabled in comments.',
            NodeType::DEFINITION_DESCRIPTION => 'Definition lists are disabled in comments.',
            NodeType::THEMATIC_BREAK => 'Horizontal rules are disabled in comments.',
            NodeType::LINE_BLOCK => 'Line blocks are disabled in comments.',
            NodeType::SPAN => 'Custom spans are disabled in comments.',
            NodeType::SYMBOL => 'Symbol markup is disabled in comments.',
            NodeType::MATH => 'Math markup is disabled in comments.',
            NodeType::ABBREVIATION => 'Abbreviations are disabled in comments.',
        ];

        return $profile;
    }

    /**
     * Create a minimal profile suitable for chat or short-form input
     *
     * Allows all trivial inline formatting:
     * - Basic: text, bold, italic, strikethrough, code
     * - Advanced: superscript, subscript, insert, delete
     * - Breaks: soft/hard line breaks
     *
     * Blocks limited to paragraphs and lists. Suitable for:
     * - Chat messages
     * - Micro-posts
     * - Short form content
     */
    public static function minimal(): self
    {
        $profile = new self();
        $profile->name = 'minimal';
        $profile->description = 'Chat/micro-posts. Non-destructive inline formatting, paragraphs and lists.';
        $profile
            ->allowInline([
                NodeType::TEXT,
                NodeType::EMPHASIS,
                NodeType::STRONG,
                NodeType::UNDERLINE,
                NodeType::STRIKE,
                NodeType::INLINE_EXTENSION,
                NodeType::MENTION,
                NodeType::CODE,
                NodeType::DELETE,
                NodeType::INSERT,
                NodeType::SUPERSCRIPT,
                NodeType::SUBSCRIPT,
                NodeType::SOFT_BREAK,
                NodeType::HARD_BREAK,
            ])
            ->allowBlock([
                NodeType::PARAGRAPH,
                NodeType::LIST_BLOCK,
                NodeType::LIST_ITEM,
            ])
            ->setMaxNesting(2)
            ->setMaxLength(self::MINIMAL_MAX_LENGTH);

        $profile->featureReasons = [
            NodeType::LINK => 'Links are disabled in this minimal context.',
            NodeType::HIGHLIGHT => 'Highlighting is disabled in this minimal context.',
            NodeType::IMAGE => 'Images are disabled in this minimal context.',
            NodeType::RAW_INLINE => 'Raw HTML is disabled for security reasons.',
            NodeType::FOOTNOTE_REF => 'Footnotes are disabled in this minimal context.',
            NodeType::INLINE_FOOTNOTE => 'Footnotes are disabled in this minimal context.',
            NodeType::SPAN => 'Custom spans are disabled in this minimal context.',
            NodeType::SYMBOL => 'Symbols are disabled in this minimal context.',
            NodeType::MATH => 'Math is disabled in this minimal context.',
            NodeType::ABBREVIATION => 'Abbreviations are disabled in this minimal context.',
            'default' => 'Only basic text formatting and lists are allowed in this context.',
        ];

        return $profile;
    }

    /**
     * Get the profile name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the profile description
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Get the reason why a specific node type is disallowed
     *
     * Returns null if the node type is allowed or no specific reason is set.
     */
    public function getReasonDisallowed(string $nodeType): ?string
    {
        if ($this->isTypeAllowed($nodeType)) {
            return null;
        }

        return $this->featureReasons[$nodeType] ?? $this->featureReasons['default'] ?? null;
    }

    /**
     * Get all feature restriction reasons
     *
     * @return array<string, string>
     */
    public function getFeatureReasons(): array
    {
        return $this->featureReasons;
    }

    /**
     * Set a reason for why a feature is disallowed
     */
    public function setFeatureReason(string $nodeType, string $reason): self
    {
        $this->featureReasons[$nodeType] = $reason;

        return $this;
    }

    /**
     * Set allowed inline types (null means all allowed)
     *
     * @param list<string>|null $types
     */
    public function allowInline(?array $types): self
    {
        $this->allowedInline = $types;

        return $this;
    }

    /**
     * Set allowed block types (null means all allowed)
     *
     * @param list<string>|null $types
     */
    public function allowBlock(?array $types): self
    {
        $this->allowedBlock = $types;

        return $this;
    }

    /**
     * Add types to the inline deny list
     *
     * @param list<string> $types
     */
    public function denyInline(array $types): self
    {
        $this->deniedInline = array_merge($this->deniedInline, $types);

        return $this;
    }

    /**
     * Add types to the block deny list
     *
     * @param list<string> $types
     */
    public function denyBlock(array $types): self
    {
        $this->deniedBlock = array_merge($this->deniedBlock, $types);

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getAllowedInline(): ?array
    {
        return $this->allowedInline;
    }

    /**
     * @return list<string>|null
     */
    public function getAllowedBlock(): ?array
    {
        return $this->allowedBlock;
    }

    /**
     * @return list<string>
     */
    public function getDeniedInline(): array
    {
        return $this->deniedInline;
    }

    /**
     * @return list<string>
     */
    public function getDeniedBlock(): array
    {
        return $this->deniedBlock;
    }

    public function getLinkPolicy(): ?LinkPolicy
    {
        return $this->linkPolicy;
    }

    public function setLinkPolicy(?LinkPolicy $policy): self
    {
        $this->linkPolicy = $policy;

        return $this;
    }

    public function getMaxNesting(): int
    {
        return $this->maxNesting;
    }

    /**
     * Set maximum nesting depth (0 = unlimited)
     */
    public function setMaxNesting(int $max): self
    {
        $this->maxNesting = $max;

        return $this;
    }

    public function getMaxLength(): int
    {
        return $this->maxLength;
    }

    /**
     * Set maximum input length in bytes (0 = unlimited)
     */
    public function setMaxLength(int $max): self
    {
        $this->maxLength = $max;

        return $this;
    }

    public function getDisallowedAction(): string
    {
        return $this->disallowedAction;
    }

    /**
     * Set action for disallowed elements
     *
     * @param string $action One of ACTION_STRIP, ACTION_TO_TEXT, ACTION_ERROR
     */
    public function onDisallowed(string $action): self
    {
        $this->disallowedAction = $action;

        return $this;
    }

    /**
     * Check if a node is allowed by this profile
     */
    public function isNodeAllowed(Node $node): bool
    {
        return $this->isTypeAllowed(self::canonicalTypeOf($node));
    }

    /**
     * The canonical type name for a node.
     *
     * Two constructs are not their own node class here - an autolink is a Link
     * carrying a flag, an admonition a Div carrying one - so `getType()` reports
     * the broader name and a profile naming the narrower one matched nothing.
     * That was silent: a host could deny autolinks, get no error and no
     * violation, and still emit them (carve#362).
     */
    public static function canonicalTypeOf(Node $node): string
    {
        if ($node instanceof Link && $node->isAutolink()) {
            return NodeType::AUTOLINK;
        }
        if ($node instanceof Div && $node->isTyped()) {
            return NodeType::ADMONITION;
        }

        return $node->getType();
    }

    /**
     * Types that are a SPECIALIZATION of a broader one.
     *
     * A subtype stays COVERED BY the broader name: a profile that denies `link`
     * must keep stripping autolinks, and one that denies `div` must keep
     * stripping admonitions. Otherwise naming them separately would quietly
     * widen every profile already written against the broad name - the opposite
     * of what a deny list is for.
     *
     * @return list<string> The type itself, plus its supertype when it has one.
     */
    protected static function withSupertype(string $type): array
    {
        return match ($type) {
            NodeType::AUTOLINK => [NodeType::AUTOLINK, NodeType::LINK],
            NodeType::ADMONITION => [NodeType::ADMONITION, NodeType::DIV],
            default => [$type],
        };
    }

    /**
     * Types that are not their own trust class.
     *
     * Folding happens BEFORE resolution, never inside it: a fold says "this
     * node has the same capability as that one", which is a different question
     * from whether a profile allows it. Every entry here is normative in
     * profiles.md.
     */
    protected static function trustClass(string $type): string
    {
        return match ($type) {
            // A code span with the `<code>` wrapper dropped (PART 9 §27):
            // same verbatim capture, escaping and trailing-attribute surface.
            NodeType::LITERAL_INLINE => NodeType::CODE,
            // Typographic substitution is ordinary visible prose. An em dash is
            // not a different trust level from the words around it.
            'smart_punctuation' => NodeType::TEXT,
            // `@user` and `#tag` share boundary rules and render through the
            // same inert-span mechanism, so they are one trust class.
            'tag' => NodeType::MENTION,
            default => $type,
        };
    }

    /**
     * Types outside the vocabulary that are always allowed.
     *
     * A profile cannot name these, so denying them would express nothing:
     * `raw_text` serves the formatter, `abbreviation_def` and `frontmatter`
     * render nothing, and the document root is the tree itself.
     *
     * @var list<string>
     */
    private const NON_DENIABLE_TYPES = [
        'raw_text',
        'abbreviation_def',
        'frontmatter',
        'document',
    ];

    /**
     * Check if a type string is allowed by this profile
     */
    public function isTypeAllowed(string $type): bool
    {
        $type = self::trustClass($type);

        if (in_array($type, self::NON_DENIABLE_TYPES, true)) {
            return true;
        }

        if (in_array($type, NodeType::allInlineTypes(), true)) {
            return $this->isInlineAllowed($type);
        }

        if (in_array($type, NodeType::allBlockTypes(), true)) {
            return $this->isBlockAllowed($type);
        }

        // Outside the vocabulary and outside the non-deniable set: a type this
        // build does not know, e.g. one an extension introduced. profiles.md
        // §Resolution is exhaustive - there is no "deny the unrecognized" step.
        // It cannot be in a deny list (nothing can name it), and an allow list
        // excludes it by definition. Without a node we cannot tell which axis
        // it belongs to, so any allow list at all is taken as excluding it;
        // isNodeAllowed() below knows the axis and is exact.
        return $this->allowedInline === null && $this->allowedBlock === null;
    }

    /**
     * Check if an inline type is allowed
     */
    public function isInlineAllowed(string $type): bool
    {
        // A subtype answers to its own name and to the broader one.
        $names = self::withSupertype($type);

        // Check deny list first
        if (array_intersect($names, $this->deniedInline) !== []) {
            return false;
        }

        // If allowlist is set, check against it
        if ($this->allowedInline !== null) {
            return array_intersect($names, $this->allowedInline) !== [];
        }

        // Otherwise allowed
        return true;
    }

    /**
     * Check if a block type is allowed
     */
    public function isBlockAllowed(string $type): bool
    {
        // A subtype answers to its own name and to the broader one.
        $names = self::withSupertype($type);

        // Check deny list first
        if (array_intersect($names, $this->deniedBlock) !== []) {
            return false;
        }

        // If allowlist is set, check against it
        if ($this->allowedBlock !== null) {
            return array_intersect($names, $this->allowedBlock) !== [];
        }

        // Otherwise allowed
        return true;
    }

    /**
     * Get a summary of what this profile allows/denies
     *
     * @return array{name: string, description: string, allowed_block: list<string>|string, allowed_inline: list<string>|string, denied_block: list<string>, denied_inline: list<string>}
     */
    public function getSummary(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'allowed_block' => $this->allowedBlock ?? 'all',
            'allowed_inline' => $this->allowedInline ?? 'all',
            'denied_block' => $this->deniedBlock,
            'denied_inline' => $this->deniedInline,
        ];
    }
}
