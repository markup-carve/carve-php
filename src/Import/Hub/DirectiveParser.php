<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use League\CommonMark\Parser\Block\BlockContinue;
use League\CommonMark\Parser\Block\BlockContinueParserInterface;
use League\CommonMark\Parser\Block\BlockStart;
use League\CommonMark\Parser\Block\BlockStartParserInterface;
use League\CommonMark\Parser\Cursor;
use League\CommonMark\Parser\MarkdownParserStateInterface;

/**
 * Container directives as `micromark-extension-directive` reads them: `:::name[label]{attributes}`, the content as
 * Markdown, up to a `:::` run at least as long as the opener.
 */
final class DirectiveParser extends AbstractBlockContinueParser implements BlockStartParserInterface
{
    /**
     * @var string
     */
    private const OPENER = '/\G[ \t]*(:{3,})([A-Za-z][A-Za-z0-9_-]*)'
        . '(?:\[((?:[^\[\]\\\\\n]|\\\\.|\[(?:[^\[\]\\\\\n]|\\\\.)*\])*)\])?'
        . '(?:\{([^{}\n]*)\})?[ \t]*$/';

    /**
     * @var string
     */
    private const ATTRIBUTE = '/\G[ \t]*(?:#([^\s#.="\'{}]+)|\.([^\s#.="\'{}]+)'
        . '|([A-Za-z_:][\w.:-]*)(?:=(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`{}]+)))?)/';

    private ?Directive $block = null;

    public function __construct(private int $length = 0, private int $offset = 0)
    {
    }

    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        if ($cursor->isIndented() || $cursor->getNextNonSpaceCharacter() !== ':') {
            return BlockStart::none();
        }
        $indent = $cursor->getIndent();
        if (preg_match(self::OPENER, $cursor->getRemainder(), $match) !== 1) {
            return BlockStart::none();
        }
        $attributes = self::attributes($match[4] ?? '');
        if ($attributes === null) {
            return BlockStart::none();
        }
        $cursor->advanceToEnd();
        $parser = new self(strlen($match[1]), $indent);
        $parser->block = new Directive($match[2], $attributes);
        $label = $match[3] ?? '';

        return ($label === '' ? BlockStart::of($parser) : BlockStart::of($parser, new DirectiveLabelParser($label)))->at($cursor);
    }

    public function getBlock(): Directive
    {
        return $this->block ?? new Directive('', []);
    }

    public function isContainer(): bool
    {
        return true;
    }

    public function canContain(AbstractBlock $childBlock): bool
    {
        return true;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): BlockContinue
    {
        if (
            !$cursor->isIndented()
            && preg_match('/^[ \t]*(:{3,})[ \t]*$/', $cursor->getRemainder(), $match) === 1
            && strlen($match[1]) >= $this->length
        ) {
            $cursor->advanceToEnd();

            return BlockContinue::finished();
        }
        $offset = $this->offset;
        while ($offset > 0 && $cursor->advanceBySpaceOrTab()) {
            $offset--;
        }

        return BlockContinue::at($cursor);
    }

    /**
     * @return array<array{string, string}>|null Null where the braces do not hold attributes.
     */
    private static function attributes(string $source): ?array
    {
        $out = [];
        $at = 0;
        $length = strlen($source);
        while ($at < $length) {
            if (trim(substr($source, $at)) === '') {
                break;
            }
            if (preg_match(self::ATTRIBUTE, $source, $m, 0, $at) !== 1) {
                return null;
            }
            $at += strlen($m[0]);
            if (($m[1] ?? '') !== '') {
                $out[] = ['id', $m[1]];
            } elseif (($m[2] ?? '') !== '') {
                $out[] = ['class', $m[2]];
            } else {
                $out[] = [$m[3] ?? '', ($m[4] ?? '') . ($m[5] ?? '') . ($m[6] ?? '')];
            }
            if ($at < $length && !in_array($source[$at], [' ', "\t"], true)) {
                return null;
            }
        }

        return $out;
    }
}
