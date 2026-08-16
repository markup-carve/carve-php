<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Node;

class TemplateSourceLinter
{
    /**
     * @var string
     */
    public const RULE_BRACED_COMMENT_IN_A_TEMPLATE_SOURCE = 'braced-comment-in-a-template-source';

    /**
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source): array
    {
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();
        $document = $converter->parse($source);
        $comments = [];
        $this->collectDelimitedComments($document, $comments);

        $hasTemplateTag = false;
        foreach ($comments as $comment) {
            $content = trim($comment->getContent(), " \t\r\n");
            if (preg_match('/^(?:raw|endraw|endif|endfor|endblock|(?:if|for|block)[ \t\r\n]+.+)$/s', $content) === 1) {
                $hasTemplateTag = true;

                break;
            }
        }
        if (!$hasTemplateTag) {
            return [];
        }

        $byteAt = SourceOffsets::map($source);
        $sourceLength = strlen($source);
        $warnings = [];
        foreach ($comments as $comment) {
            $pos = $comment->getPos();
            if ($pos === null) {
                $warnings[] = new LintWarning(
                    1,
                    1,
                    self::RULE_BRACED_COMMENT_IN_A_TEMPLATE_SOURCE,
                    $this->message(),
                    0,
                    0,
                );

                continue;
            }
            $warnings[] = new LintWarning(
                $pos->startLine,
                $pos->startColumn,
                self::RULE_BRACED_COMMENT_IN_A_TEMPLATE_SOURCE,
                $this->message(),
                SourceOffsets::toByte($pos->startOffset, $byteAt, $sourceLength),
                SourceOffsets::toByte($pos->endOffset, $byteAt, $sourceLength),
            );
        }

        return $warnings;
    }

    private function message(): string
    {
        return 'A braced comment appears in text that also contains template tags. '
            . 'Liquid, Nunjucks, or Twig source may have reached Carve before template rendering; '
            . 'the comment is reported but never rewritten.';
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param list<\MarkupCarve\Carve\Node\Block\Comment> $comments
     */
    private function collectDelimitedComments(Node $node, array &$comments): void
    {
        if ($node instanceof Comment && $node->isDelimited()) {
            $comments[] = $node;
        }
        foreach ($node->getChildren() as $child) {
            $this->collectDelimitedComments($child, $comments);
        }
    }
}
