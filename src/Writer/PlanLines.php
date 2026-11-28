<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use MarkupCarve\Carve\AttributeParser;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Block\ImageLine;
use MarkupCarve\Carve\Block\LineShapes;
use MarkupCarve\Carve\Snippet\Syntax;

/**
 * The line-start decisions of the JavaScript writer planner.
 *
 * @phpstan-type Place array{paragraph: bool, inItem: bool, inQuote: bool, inDescriptionList: bool, nested: bool,
 *   item: 'plain'|'task'|null, before: string|null, followed: bool, attributed: bool, documentStart: bool,
 *   line: bool, term: bool, capped: bool}
 */
final class PlanLines
{
    public const NO_ESCAPE = -1;

    public const UNDECIDED = -2;

    private static function code(PlanLeaf $leaf, int $at): int
    {
        return $leaf->codes[$at] ?? -1;
    }

    private static function blank(int $code): bool
    {
        return $code === 32 || $code === 9;
    }

    private static function lineEnd(int $code): bool
    {
        return $code === 10 || $code === -1;
    }

    private static function runEnd(PlanLeaf $leaf, int $at, int $code): int
    {
        while (self::code($leaf, $at) === $code) {
            $at++;
        }

        return $at;
    }

    private static function hasContentBehind(PlanLeaf $leaf, int $at, bool $glued = false): bool
    {
        if (!$glued && self::code($leaf, $at) !== 32) {
            return false;
        }
        $end = $glued ? $at + 1 : $at;
        while (self::blank(self::code($leaf, $end))) {
            $end++;
        }

        return !self::lineEnd(self::code($leaf, $end));
    }

    private static function onlyLineCommentBehind(PlanLeaf $leaf, int $at): bool
    {
        while (self::blank(self::code($leaf, $at))) {
            $at++;
        }

        return ($leaf->ownerTypes[$at] ?? '') === 'comment' && ($leaf->ownerForms[$at] ?? '') === 'line';
    }

    private static function restIsBlank(PlanLeaf $leaf, int $at): bool
    {
        while (self::blank(self::code($leaf, $at))) {
            $at++;
        }

        return self::lineEnd(self::code($leaf, $at));
    }

    private static function lastOfLine(PlanLeaf $leaf, int $at): int
    {
        $end = $at;
        $length = count($leaf->codes);
        while ($end < $length && $leaf->codes[$end] !== 10) {
            $end++;
        }
        while ($end > $at && self::blank(self::code($leaf, $end - 1))) {
            $end--;
        }

        return $end - 1;
    }

    private static function lineEndsWith(PlanLeaf $leaf, int $at, int $code): bool
    {
        $last = self::lastOfLine($leaf, $at);

        return $last > $at && self::code($leaf, $last) === $code;
    }

    private static function heading(PlanLeaf $leaf, int $at, int $code): int
    {
        $end = self::runEnd($leaf, $at, $code);
        if ($end - $at > 6) {
            return self::NO_ESCAPE;
        }
        if (self::code($leaf, $end) === 32) {
            return $at;
        }
        if (self::code($leaf, $end) === 9) {
            return self::UNDECIDED;
        }

        return self::lineEnd(self::code($leaf, $end)) ? self::UNDECIDED : self::NO_ESCAPE;
    }

    private static function digits(PlanLeaf $leaf, int $at): int
    {
        $end = $at;
        while (self::code($leaf, $end) >= 48 && self::code($leaf, $end) <= 57) {
            $end++;
        }
        $mark = self::code($leaf, $end);
        if ($end - $at > 9 || ($mark !== 46 && $mark !== 41)) {
            return self::NO_ESCAPE;
        }
        if (self::code($leaf, $end + 1) === 123) {
            return self::markerBlock($leaf, $end + 1);
        }

        return self::hasContentBehind($leaf, $end + 1) ? $end : self::NO_ESCAPE;
    }

    private static function bullet(PlanLeaf $leaf, int $at): int
    {
        if (self::code($leaf, $at + 1) === 123) {
            return self::markerBlock($leaf, $at + 1);
        }

        return self::hasContentBehind($leaf, $at + 1) ? $at : self::NO_ESCAPE;
    }

    private static function anyLine(PlanLeaf $leaf, int $at, int $code): int
    {
        $end = self::runEnd($leaf, $at, $code);
        if ($code === 96) {
            return $end - $at >= 3 && !self::tickBehind($leaf, $end) ? $at : self::NO_ESCAPE;
        }
        if ($code === 58) {
            return $end - $at < 3 ? self::NO_ESCAPE : self::divLine($leaf, $at, $end);
        }
        if ($code === 37) {
            return $end - $at < 3 ? self::NO_ESCAPE : self::fence($leaf, $at, $end);
        }
        if ($code === 42) {
            return $end - $at >= 3 && self::restIsBlank($leaf, $end) ? $at : self::NO_ESCAPE;
        }
        if ($code === 124) {
            return self::pipeLine($leaf, $at);
        }

        return self::NO_ESCAPE;
    }

    private static function tickBehind(PlanLeaf $leaf, int $at): bool
    {
        for (; !self::lineEnd(self::code($leaf, $at)); $at++) {
            if (self::code($leaf, $at) === 96) {
                return true;
            }
        }

        return false;
    }

    private static function divLine(PlanLeaf $leaf, int $at, int $end): int
    {
        $after = $end;
        while (self::code($leaf, $after) === 32) {
            $after++;
        }
        if (self::lineEnd(self::code($leaf, $after)) || $after > $end) {
            return $at;
        }
        if (self::code($leaf, $after) !== 9) {
            return self::NO_ESCAPE;
        }
        while (self::blank(self::code($leaf, $after))) {
            $after++;
        }

        return self::lineEnd(self::code($leaf, $after)) ? $at : self::NO_ESCAPE;
    }

    private static function fence(PlanLeaf $leaf, int $at, int $end): int
    {
        return self::code($leaf, $end) === 32 || self::lineEnd(self::code($leaf, $end)) ? $at : self::UNDECIDED;
    }

    private static function quoteMark(PlanLeaf $leaf, int $at): int
    {
        $next = self::code($leaf, $at + 1);
        if ($next === 32 || self::lineEnd($next)) {
            return $at;
        }

        return $next === 9 ? self::UNDECIDED : self::NO_ESCAPE;
    }

    private static function bareDot(PlanLeaf $leaf, int $at): int
    {
        if (self::code($leaf, $at + 1) === 123) {
            return self::markerBlock($leaf, $at + 1);
        }
        if (!self::hasContentBehind($leaf, $at + 1)) {
            return self::NO_ESCAPE;
        }
        $content = $at + 1;
        while (self::blank(self::code($leaf, $content))) {
            $content++;
        }
        $next = self::code($leaf, $content + 1);

        return self::code($leaf, $content) === 46 && self::blank($next) ? self::NO_ESCAPE : $at;
    }

    private static function markerBlock(PlanLeaf $leaf, int $at): int
    {
        return self::braceBlocksEnd($leaf, $at) === self::NO_ESCAPE ? self::NO_ESCAPE : self::UNDECIDED;
    }

    /**
     * @phpstan-param Place $place
     */
    private static function hyphenLine(PlanLeaf $leaf, int $at, array $place): int
    {
        $end = self::runEnd($leaf, $at, 45);
        $fence = $place['documentStart'] && $end - $at === 3;
        if ($end - $at >= 3 && !$fence && self::hasContentBehind($leaf, $end - 1, true)) {
            return self::NO_ESCAPE;
        }
        if ($end - $at < 3 || !$place['documentStart']) {
            return self::bullet($leaf, $at);
        }
        for ($unit = $at; $unit < $end; $unit++) {
            if ($leaf->kinds[$unit] !== PlanLeaf::TEXT) {
                return self::UNDECIDED;
            }
        }

        return self::bullet($leaf, $at);
    }

    private static function pipeLine(PlanLeaf $leaf, int $at): int
    {
        $close = $at + 1;
        while (!self::lineEnd(self::code($leaf, $close)) && self::code($leaf, $close) !== 124) {
            $close++;
        }
        if (self::code($leaf, $close) !== 124) {
            return self::NO_ESCAPE;
        }
        if (self::lineEndsWith($leaf, $at, 124)) {
            return $leaf->kinds[self::lastOfLine($leaf, $at)] === PlanLeaf::TEXT ? $at : self::UNDECIDED;
        }
        $last = $at;
        for ($unit = $at + 1, $length = count($leaf->codes); $unit < $length && $leaf->codes[$unit] !== 10; $unit++) {
            if ($leaf->codes[$unit] === 124) {
                $last = $unit;
            }
        }
        if ($last === $at) {
            return self::NO_ESCAPE;
        }

        return $leaf->kinds[$last] !== PlanLeaf::TEXT || self::mayCloseRow($leaf, $last + 1)
            ? self::UNDECIDED : self::NO_ESCAPE;
    }

    private static function mayCloseRow(PlanLeaf $leaf, int $at): bool
    {
        $brace = self::code($leaf, $at) === 32 && self::code($leaf, $at + 1) === 123 ? $at + 1 : $at;
        $unit = self::code($leaf, $brace) === 123 ? self::braceBlocksEnd($leaf, $brace) : $at;
        if ($unit < 0) {
            return $unit === self::UNDECIDED;
        }
        $blanks = $unit;
        while (self::blank(self::code($leaf, $unit))) {
            $unit++;
        }
        $value = self::code($leaf, $unit);

        return self::lineEnd($value) || ($unit > $blanks && $value === 37 && self::code($leaf, $unit + 1) === 37);
    }

    private static function braceBlocksEnd(PlanLeaf $leaf, int $at): int
    {
        if (($leaf->kinds[$at] ?? null) === PlanLeaf::OPEN) {
            return self::NO_ESCAPE;
        }
        $end = $at;
        $close = -1;
        $length = count($leaf->codes);
        while ($end < $length && $leaf->codes[$end] !== 10) {
            if ($leaf->codes[$end] === 125) {
                $close = $end;
            }
            $end++;
        }
        for ($unit = $at; $unit < $close; $unit++) {
            $value = $leaf->codes[$unit];
            if ($leaf->kinds[$unit] !== PlanLeaf::TEXT || $value === 34 || $value === 39 || $value === 92) {
                return self::UNDECIDED;
            }
        }
        $text = $leaf->text($at, $end);
        $block = 0;
        while (($text[$block] ?? '') === '{') {
            $next = AttributeParser::read($text, $block, strlen($text), new Attrs());
            if ($next < 0) {
                return $block === 0 ? self::NO_ESCAPE : $at + $block;
            }
            $block = $next;
        }

        return $at + $block;
    }

    private static function percent(PlanLeaf $leaf, int $at): int
    {
        return self::code($leaf, $at + 1) === 37 ? $at : self::NO_ESCAPE;
    }

    /**
     * @phpstan-param Place $place
     */
    private static function firstLine(PlanLeaf $leaf, int $at, array $place): int
    {
        $value = self::code($leaf, $at);
        if ($value === 35 || $value === 61) {
            return self::heading($leaf, $at, $value);
        }
        if ($value >= 48 && $value <= 57) {
            return self::digits($leaf, $at);
        }

        return match ($value) {
            62 => self::quoteMark($leaf, $at),
            45 => self::hyphenLine($leaf, $at, $place),
            42 => self::runEnd($leaf, $at, 42) - $at >= 3
                ? self::anyLine($leaf, $at, 42) : self::starLine($leaf, $at, $place),
            46 => self::bareDot($leaf, $at),
            58 => self::colon($leaf, $at, $place),
            default => self::otherLine($leaf, $at, $value, $place, true),
        };
    }

    /**
     * @phpstan-param Place $place
     */
    private static function colon(PlanLeaf $leaf, int $at, array $place): int
    {
        $end = self::runEnd($leaf, $at, 58);
        if ($end - $at >= 3) {
            return self::divLine($leaf, $at, $end);
        }
        if ($end - $at === 2) {
            return self::code($leaf, $end) === 32 ? $at
                : (self::code($leaf, $end) === 9 ? self::UNDECIDED : self::NO_ESCAPE);
        }
        if (!self::blank(self::code($leaf, $end)) && !self::lineEnd(self::code($leaf, $end))) {
            return self::NO_ESCAPE;
        }

        return $place['before'] === 'definition_list' || $place['inDescriptionList']
            ? self::UNDECIDED : self::NO_ESCAPE;
    }

    /**
     * @phpstan-param Place $place
     */
    private static function laterColon(PlanLeaf $leaf, int $at, array $place): int
    {
        $run = self::runEnd($leaf, $at, 58) - $at;
        if ($place['term'] && $run < 3) {
            return self::NO_ESCAPE;
        }

        return $place['inItem'] && $run === 2 ? self::NO_ESCAPE : self::colon($leaf, $at, $place);
    }

    /**
     * @phpstan-param Place $place
     */
    private static function laterLine(PlanLeaf $leaf, int $at, array $place): int
    {
        $value = self::code($leaf, $at);
        if ($value === 123) {
            return self::NO_ESCAPE;
        }
        if ($place['inDescriptionList'] && $value === 58) {
            return self::laterColon($leaf, $at, $place);
        }
        if ($place['inItem'] && $value >= 48 && $value <= 57) {
            return $value === 49 && self::code($leaf, $at + 1) < 48 ? self::digits($leaf, $at) : self::NO_ESCAPE;
        }
        if ($place['inItem'] && ($value === 45 || $value === 42 || $value === 46)) {
            return self::firstLine($leaf, $at, $place);
        }
        if ($value === 42 && self::runEnd($leaf, $at, 42) - $at < 3) {
            return self::NO_ESCAPE;
        }
        if (!$place['inItem'] && self::letter($value)) {
            return self::NO_ESCAPE;
        }

        return self::otherLine($leaf, $at, $value, $place, false);
    }

    /**
     * @phpstan-param Place $place
     */
    private static function otherLine(PlanLeaf $leaf, int $at, int $value, array $place, bool $first): int
    {
        return match ($value) {
            96, 58, 42, 124 => self::anyLine($leaf, $at, $value),
            37 => self::percent($leaf, $at),
            32, 9 => self::UNDECIDED,
            91 => self::bracketLine($leaf, $at, $place, $first),
            123 => self::braceLine($leaf, $at, $place, $first),
            94 => self::caption($leaf, $at, $place, $first),
            default => self::letterMarker($leaf, $at),
        };
    }

    /**
     * @phpstan-param Place $place
     */
    private static function caption(PlanLeaf $leaf, int $at, array $place, bool $first): int
    {
        if (self::code($leaf, $at + 1) !== 9 && !self::hasContentBehind($leaf, $at + 1)) {
            return self::NO_ESCAPE;
        }
        if (self::code($leaf, $at + 1) === 32 && !self::onlyLineCommentBehind($leaf, $at + 1)) {
            return $at;
        }
        if ($first) {
            return $place['before'] !== null ? self::UNDECIDED : self::NO_ESCAPE;
        }
        if (!self::mayBeBlockImage($leaf, $at)) {
            return self::NO_ESCAPE;
        }
        $unit = $at - 2;
        for (; $unit >= 0 && $leaf->codes[$unit] !== 10; $unit--) {
            if ($leaf->kinds[$unit] !== PlanLeaf::TEXT && $leaf->kinds[$unit] !== PlanLeaf::ESCAPE) {
                return self::UNDECIDED;
            }
        }
        if (self::code($leaf, $unit + 1) !== 33) {
            return self::NO_ESCAPE;
        }
        $above = $leaf->text($unit + 1, $at - 1);

        return ImageLine::isImageOnly($above, 0, strlen($above)) ? self::UNDECIDED : self::NO_ESCAPE;
    }

    private static function mayBeBlockImage(PlanLeaf $leaf, int $end): bool
    {
        $depth = 0;
        for ($unit = 0; $unit < $end; $unit++) {
            $kind = $leaf->kinds[$unit];
            if ($kind === PlanLeaf::OPEN && $depth++ === 0 && $leaf->ownerTypes[$unit] !== 'image') {
                return false;
            }
            if ($kind === PlanLeaf::CLOSE) {
                $depth--;
            } elseif ($kind !== PlanLeaf::OPEN && $depth === 0 && !self::blankOrImage($leaf, $unit)) {
                return false;
            }
        }

        return true;
    }

    private static function blankOrImage(PlanLeaf $leaf, int $unit): bool
    {
        $kind = $leaf->kinds[$unit];
        $value = $leaf->codes[$unit];
        if ($kind === PlanLeaf::TEXT) {
            return self::blank($value) || $value === 10;
        }
        $type = $leaf->ownerTypes[$unit];
        if ($type === 'math') {
            return ($leaf->nodeAt($unit)['display'] ?? false) === true;
        }

        return $kind !== PlanLeaf::ATOM || in_array($type, ['image', 'comment', 'soft_break', 'hard_break'], true);
    }

    /**
     * @phpstan-param Place $place
     */
    private static function attributeLine(PlanLeaf $leaf, int $at, array $place, bool $first): int
    {
        if (self::bracedOpener(self::code($leaf, $at + 1)) || !self::lineEndsWith($leaf, $at, 125)) {
            return self::NO_ESCAPE;
        }
        if (!$first) {
            return self::UNDECIDED;
        }
        [$line, $whole] = self::textOfLine($leaf, $at);
        if (!$whole) {
            return self::UNDECIDED;
        }
        if (!LineShapes::attributeLine($line, 0, strlen($line), new Attrs())) {
            return str_starts_with($line, '{{') || self::mayTakeEscape($line)
                ? self::UNDECIDED : self::NO_ESCAPE;
        }
        $below = $at + strlen($line) + 1;
        $next = self::nextStructuralLine($leaf, $below);
        if ($next > count($leaf->codes)) {
            return $place['attributed'] || $next > $below ? $at
                : ($place['followed'] ? self::UNDECIDED : self::NO_ESCAPE);
        }
        [$after, $afterWhole] = self::textOfLine($leaf, $next);

        return $afterWhole && LineShapes::attributeLine($after, 0, strlen($after), new Attrs())
            ? self::UNDECIDED : $at;
    }

    /**
     * @phpstan-param Place $place
     */
    private static function braceLine(PlanLeaf $leaf, int $at, array $place, bool $first): int
    {
        if ($first && !$place['nested'] && self::code($leaf, $at + 1) === 36) {
            $definition = self::snippetDefinitionLine($leaf, $at);
            if ($definition !== self::NO_ESCAPE) {
                return $place['attributed'] ? self::UNDECIDED : $definition;
            }
        }

        return self::attributeLine($leaf, $at, $place, $first);
    }

    private static function snippetDefinitionLine(PlanLeaf $leaf, int $at): int
    {
        $end = self::textEnd($leaf, $at);
        $text = $leaf->text($at, $end);
        $marker = Syntax::marker($text, 0, strlen($text));
        if ($marker === null) {
            $cut = $end < count($leaf->codes) && $leaf->codes[$end] !== 10;

            return $cut && Syntax::nameEnd($text, 2, strlen($text)) > 0
                ? self::UNDECIDED : self::NO_ESCAPE;
        }
        for ($unit = $at; $unit < $at + $marker[2]; $unit++) {
            if ($leaf->kinds[$unit] !== PlanLeaf::TEXT) {
                return self::UNDECIDED;
            }
        }
        if ($marker[3] || self::code($leaf, $at + $marker[2]) !== 32) {
            return self::NO_ESCAPE;
        }
        for (
            $unit = $at + $marker[2] + 1, $length = count($leaf->codes);
            $unit < $length && $leaf->codes[$unit] !== 10; $unit++
        ) {
            if ($leaf->kinds[$unit] !== PlanLeaf::TEXT || !self::blank($leaf->codes[$unit])) {
                return $at;
            }
        }

        return self::NO_ESCAPE;
    }

    private static function nextStructuralLine(PlanLeaf $leaf, int $start): int
    {
        $next = $start;
        $length = count($leaf->codes);
        while (($leaf->ownerTypes[$next] ?? '') === 'comment' && ($leaf->ownerForms[$next] ?? '') === 'line') {
            while ($next < $length && $leaf->codes[$next] !== 10) {
                $next++;
            }
            $next++;
        }

        return $next;
    }

    private static function mayTakeEscape(string $line): bool
    {
        return strpbrk($line, "\"'\\") !== false;
    }

    private static function bracedOpener(int $code): bool
    {
        return $code >= 0 && $code < 128 && str_contains('*+,-/^~=', chr($code));
    }

    private static function definitionShape(PlanLeaf $leaf, int $at): bool
    {
        for ($unit = $at, $length = count($leaf->codes); $unit < $length && $leaf->codes[$unit] !== 10; $unit++) {
            if ($leaf->codes[$unit] === 93 && self::code($leaf, $unit + 1) === 58) {
                return true;
            }
        }

        return false;
    }

    /**
     * @phpstan-param Place $place
     */
    private static function bracketLine(PlanLeaf $leaf, int $at, array $place, bool $first): int
    {
        if (!$first) {
            return self::NO_ESCAPE;
        }
        if ($place['item'] !== null && $place['before'] === null) {
            return self::taskBoxLine($leaf, $at, $place['item']);
        }
        if ($place['nested'] || !self::definitionShape($leaf, $at)) {
            return self::NO_ESCAPE;
        }
        if ($leaf->kinds[$at] === PlanLeaf::ATOM) {
            return self::isDefinitionLine($leaf, $at) ? self::UNDECIDED : self::NO_ESCAPE;
        }
        [$line, $whole] = self::textOfLine($leaf, $at);
        if (!$whole) {
            return self::UNDECIDED;
        }

        return self::isDefinition($line) ? $at : self::NO_ESCAPE;
    }

    /**
     * @phpstan-param Place $place
     */
    private static function starLine(PlanLeaf $leaf, int $at, array $place): int
    {
        if (self::code($leaf, $at + 1) !== 91 || $place['nested']) {
            return self::bullet($leaf, $at);
        }
        $mark = $leaf->kinds[$at] !== PlanLeaf::TEXT;
        $shape = $mark ? '*' . self::lineShape($leaf, $at + 1) : self::lineShape($leaf, $at);
        if (LineShapes::abbreviationDef($shape, 0, strlen($shape)) === null) {
            return self::NO_ESCAPE;
        }

        return $mark ? $at + 1 : $at;
    }

    private static function taskBoxLine(PlanLeaf $leaf, int $at, string $item): int
    {
        if (self::code($leaf, $at + 2) !== 93) {
            return self::NO_ESCAPE;
        }
        if ($item === 'task') {
            return self::UNDECIDED;
        }
        $shape = self::lineShape($leaf, $at);
        if (
            strlen($shape) < 4 || $shape[0] !== '[' || $shape[2] !== ']'
            || !str_contains(' xX_/!->?', $shape[1]) || $shape[3] !== ' '
        ) {
            return self::NO_ESCAPE;
        }

        return strlen(ltrim(substr($shape, 4), " \t")) > 0 ? $at : self::NO_ESCAPE;
    }

    private static function isDefinitionLine(PlanLeaf $leaf, int $at): bool
    {
        $end = $at;
        $length = count($leaf->codes);
        while ($end < $length && $leaf->codes[$end] !== 10) {
            $end++;
        }

        return self::isDefinition($leaf->text($at, $end));
    }

    private static function isDefinition(string $line): bool
    {
        $end = strlen($line);

        return LineShapes::footnoteDef($line, 0, $end) !== null
            || LineShapes::linkDef($line, 0, $end) !== null
            || LineShapes::abbreviationDef($line, 0, $end) !== null;
    }

    private static function lineShape(PlanLeaf $leaf, int $at): string
    {
        $end = self::textEnd($leaf, $at);
        $line = $leaf->text($at, $end);
        if ($end === count($leaf->codes) || $leaf->codes[$end] === 10) {
            return $line;
        }

        return $line . ($leaf->codes[$end] === 32 && $leaf->kinds[$end] === PlanLeaf::ATOM ? ' x' : 'x');
    }

    private static function textEnd(PlanLeaf $leaf, int $at): int
    {
        $end = $at;
        $length = count($leaf->codes);
        while (
            $end < $length && $leaf->codes[$end] !== 10
            && ($leaf->kinds[$end] === PlanLeaf::TEXT || $leaf->kinds[$end] === PlanLeaf::ESCAPE)
        ) {
            $end++;
        }

        return $end;
    }

    /**
     * @return array{string, bool}
     */
    private static function textOfLine(PlanLeaf $leaf, int $at): array
    {
        $end = self::textEnd($leaf, $at);

        return [$leaf->text($at, $end), $end === count($leaf->codes) || $leaf->codes[$end] === 10];
    }

    private static function letter(int $code): bool
    {
        return ($code >= 65 && $code <= 90) || ($code >= 97 && $code <= 122);
    }

    private static function letterMarker(PlanLeaf $leaf, int $at): int
    {
        $end = $at;
        while ($end - $at < 10 && self::letter(self::code($leaf, $end))) {
            $end++;
        }
        $after = self::code($leaf, $end);
        if ($end === $at || $end - $at > 9 || ($after !== 46 && $after !== 41)) {
            return self::NO_ESCAPE;
        }
        $glued = self::code($leaf, $end + 1) === 123;
        if (!$glued && !self::blank(self::code($leaf, $end + 1)) && !self::lineEnd(self::code($leaf, $end + 1))) {
            return self::NO_ESCAPE;
        }
        $letters = $leaf->text($at, $end);
        $roman = strspn($letters, 'ivxlcdm') === strlen($letters)
            || strspn($letters, 'IVXLCDM') === strlen($letters);
        if ($end - $at > 1 && !$roman) {
            return self::NO_ESCAPE;
        }

        return $glued ? self::markerBlock($leaf, $end + 1) : self::UNDECIDED;
    }

    private static function insideRawValue(PlanLeaf $leaf, int $at): bool
    {
        $owner = $leaf->owners[$at] ?? null;
        if (
            $owner === null || $leaf->kinds[$at] !== PlanLeaf::ATOM
            || ($leaf->owners[$at - 1] ?? null) !== $owner
        ) {
            return false;
        }
        $type = $leaf->ownerTypes[$at];

        return in_array($type, ['code', 'literal_inline', 'math', 'raw_inline', 'critic_comment'], true)
            || ($type === 'comment' && $leaf->ownerForms[$at] === 'braced');
    }

    private static function lineStartRule(PlanLeaf $leaf, int $at, bool $first): ?int
    {
        $owner = ($leaf->kinds[$at] ?? null) === PlanLeaf::ATOM
            && ($leaf->owners[$at - 1] ?? null) !== ($leaf->owners[$at] ?? null) ? $at : null;
        if ($owner !== null && $leaf->ownerTypes[$owner] === 'comment') {
            return $first && $leaf->ownerForms[$owner] === 'line' ? self::UNDECIDED : self::NO_ESCAPE;
        }
        $value = self::code($leaf, $at);
        if ($value === 37 && self::insideRawValue($leaf, $at)) {
            return self::NO_ESCAPE;
        }
        if ($value === 0xFEFF && $first) {
            return self::UNDECIDED;
        }

        return $value < 0 || $value >= 128 ? self::NO_ESCAPE : null;
    }

    private static function opensContainer(PlanLeaf $leaf, int $at): bool
    {
        $value = self::code($leaf, $at);
        if ($value === 42 || $value === 58) {
            return self::runEnd($leaf, $at, $value) - $at < 3;
        }

        return in_array($value, [62, 45, 46], true) || ($value >= 48 && $value <= 57) || self::letter($value);
    }

    /**
     * @phpstan-param Place $place
     */
    private static function cappedLineEscape(PlanLeaf $leaf, int $at, array $place): ?int
    {
        if (!$place['capped']) {
            return null;
        }
        $value = self::code($leaf, $at);
        if ($value === 43 && self::restIsBlank($leaf, $at + 1)) {
            return !$place['inQuote'] && $leaf->kinds[$at] === PlanLeaf::TEXT ? $at : self::UNDECIDED;
        }
        if (
            ($value === 42 && self::code($leaf, $at + 1) === 91)
            || ($value >= 48 && $value <= 57 && self::code($leaf, $at + 1) === 41)
        ) {
            return self::UNDECIDED;
        }
        if (self::opensContainer($leaf, $at)) {
            return self::NO_ESCAPE;
        }

        return $value === 58 ? self::cappedColonEscape($leaf, $at) : null;
    }

    private static function cappedColonEscape(PlanLeaf $leaf, int $at): int
    {
        $end = self::runEnd($leaf, $at, 58);
        if ($end - $at >= 3 && !self::restIsBlank($leaf, $end)) {
            return self::NO_ESCAPE;
        }

        return $leaf->kinds[$at] === PlanLeaf::TEXT ? $at : self::UNDECIDED;
    }

    /**
     * @phpstan-param Place $place
     */
    public static function lineEscape(PlanLeaf $leaf, int $at, bool $first, array $place): int
    {
        $start = self::lineStartRule($leaf, $at, $first);
        if ($start !== null) {
            return $start;
        }
        $capped = self::cappedLineEscape($leaf, $at, $place);
        if ($capped !== null) {
            return $capped;
        }
        $escape = $first ? self::firstLine($leaf, $at, $place) : self::laterLine($leaf, $at, $place);
        if ($escape < 0) {
            return $escape;
        }
        if ($leaf->kinds[$escape] !== PlanLeaf::TEXT || ($leaf->kinds[$escape - 1] ?? null) === PlanLeaf::ESCAPE) {
            return self::UNDECIDED;
        }

        return self::mayOpenBehindMarker($leaf, $escape) ? self::UNDECIDED : $escape;
    }

    private static function mayOpenBehindMarker(PlanLeaf $leaf, int $escape): bool
    {
        $mark = self::code($leaf, $escape);
        if (
            !in_array($mark, [42, 43, 45, 46, 41, 58, 62], true)
            || !self::blank(self::code($leaf, $escape + 1))
        ) {
            return false;
        }
        $behind = $escape + 1;
        while (self::blank(self::code($leaf, $behind))) {
            $behind++;
        }
        if (($leaf->kinds[$behind] ?? null) !== PlanLeaf::TEXT) {
            return false;
        }
        $value = self::code($leaf, $behind);
        if ($value >= 33 && $value <= 126 && !self::letter($value) && !($value >= 48 && $value <= 57)) {
            return true;
        }
        $end = $behind;
        while (
            self::letter(self::code($leaf, $end))
            || (self::code($leaf, $end) >= 48 && self::code($leaf, $end) <= 57)
        ) {
            $end++;
        }

        return $end > $behind && (self::code($leaf, $end) === 46 || self::code($leaf, $end) === 41);
    }
}
