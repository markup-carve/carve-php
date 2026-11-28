<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

/**
 * Builds the JavaScript planner's owned leaf units from the spellings recorded by this writer.
 * A source form that cannot be assigned to the tree's marks remains undecided for repair.
 */
final class PlanModel
{
    /**
     * @var array<int, string>
     */
    private const CONTAINERS = [
        'emphasis', 'strong', 'underline', 'strike', 'highlight', 'superscript', 'subscript', 'insert', 'delete',
        'span', 'inline_footnote', 'link', 'inline_extension',
    ];

    /**
     * @param \MarkupCarve\Carve\Writer\State $w
@param array<mixed> $nodes
     * @param int $flags
     * @param int $start
     * @param int $end
     * @param string $path
     */
    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    public static function leaf(
        State $w,
        array $nodes,
        string $path,
        int $end,
        int $start,
        int $flags = 0,
    ): void {
        $plan = $w->plan;
        if ($plan === null || $plan->undecided !== null) {
            return;
        }
        $content = $w->planContents[$path] ?? null;
        if ($content === null) {
            $plan->undecided = 'model';

            return;
        }
        $leaf = new PlanLeaf();
        self::inlines($w, $leaf, $nodes, $path, $flags);
        if ($end === Plan::AS_CONTENT) {
            $last = $nodes[count($nodes) - 1] ?? null;
            if (is_array($last) && ($last['type'] ?? null) === 'text') {
                $leaf->trimText(0);
            } elseif (
                is_array($last) && ($last['type'] ?? null) === 'hard_break'
                && ($leaf->codes[count($leaf->codes) - 1] ?? null) === 10
            ) {
                $leaf->trimLast();
            }
        }
        if ($end === Plan::AS_LINE && $leaf->codes !== []) {
            if ($leaf->codes[count($leaf->codes) - 1] !== 10) {
                $leaf->undecide('line end');
            } else {
                $leaf->trimLast();
                $leaf->push(92, PlanLeaf::ATOM, $path, -1);
            }
        }
        if ($leaf->undecided === null && $leaf->text() !== $content) {
            $leaf->undecide('model');
        }
        $place = $plan->places[$path] ?? null;
        if ($place === null) {
            $leaf->undecide('place');
        }
        $escaped = $leaf->undecided === null ? PlanResolver::analyze(
            $leaf,
            $plan,
            $start,
            $flags,
            $place,
            $w->abbreviations !== [],
            $w->labels !== [],
        ) : [];
        $plan->leaves++;
        if ($leaf->undecided !== null) {
            $plan->undecided = $leaf->undecided;
            $before = $leaf->text(max(0, $leaf->where - 16), $leaf->where);
            $after = $leaf->text($leaf->where, min(count($leaf->codes), $leaf->where + 16));
            $plan->detail = json_encode($before) . ' | ' . json_encode($after);
        } else {
            foreach ($leaf->codes as $at => $_) {
                $owner = $leaf->owners[$at];
                if ($leaf->kinds[$at] === PlanLeaf::TEXT && $leaf->ownerTypes[$at] === 'text') {
                    $plan->covered[$owner] = true;
                    if (($escaped[$at] ?? 0) !== 0) {
                        $plan->escapes[$owner][$leaf->offsets[$at]] = true;
                    }
                }
            }
            array_push($plan->lost, ...$leaf->lostValues);
        }
        array_push($plan->respell, ...$leaf->respell);
    }

    /**
     * @param \MarkupCarve\Carve\Writer\State $w
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
@param array<mixed> $nodes
     * @param int $flags
     * @param string $path
     */
    private static function inlines(State $w, PlanLeaf $leaf, array $nodes, string $path, int $flags): void
    {
        $count = count($nodes);
        for ($at = 0; $at < $count && $leaf->undecided === null; $at++) {
            $node = $nodes[$at] ?? null;
            if (!is_array($node)) {
                continue;
            }
            $nodePath = $path . '/' . $at;
            $part = $w->planParts[$nodePath]['part'] ?? null;
            if ($part === null) {
                $leaf->undecide('model');

                break;
            }
            $from = count($leaf->codes);
            self::node($w, $leaf, $node, $nodePath, $part, $flags);
            $following = $nodes[$at + 1] ?? null;
            if (
                ($node['type'] ?? '') === 'text' && is_array($following)
                && ($following['type'] ?? '') === 'soft_break'
            ) {
                $leaf->trimText($from);
            }
        }
    }

    /**
     * @param \MarkupCarve\Carve\Writer\State $w
     * @param \MarkupCarve\Carve\Writer\PlanLeaf $leaf
@param array<mixed> $node
     * @param int $flags
     * @param string $part
     * @param string $path
     */
    private static function node(State $w, PlanLeaf $leaf, array $node, string $path, string $part, int $flags): void
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        $leaf->nodes[$path] = $node;
        if ($type === 'text') {
            $value = is_string($node['value'] ?? null) ? $node['value'] : '';
            $written = $w->planParts[$path]['escaped'] ?? [];
            $escapes = [];
            foreach ($written as $at => $escaped) {
                if ($escaped) {
                    $escapes[$at] = true;
                }
            }
            $leaf->pushText($value, $path, $escapes);

            return;
        }
        if ($type === 'soft_break' || $type === 'hard_break') {
            $leaf->pushString($part, PlanLeaf::ATOM, $path, $type);

            return;
        }
        if (
            in_array($type, self::CONTAINERS, true) && is_array($node['children'] ?? null)
            && $node['children'] !== []
        ) {
            $innerPath = $path . '/children';
            $inner = $w->planContents[$innerPath] ?? null;
            $pos = $inner === null ? false : strpos($part, $inner);
            if ($inner !== null && $pos !== false) {
                $open = substr($part, 0, $pos);
                $tail = substr($part, $pos + strlen($inner));
                if ($open !== '' && $tail !== '') {
                    $leaf->pushString($open, PlanLeaf::OPEN, $path, $type);
                    $inside = match ($type) {
                        'emphasis' => 2,
                        'strong' => 4,
                        'link' => 16,
                        'inline_footnote' => 64,
                        default => 0,
                    };
                    self::inlines($w, $leaf, $node['children'], $innerPath, $flags | $inside);
                    $block = BraceBlock::of($node, ['pipes' => ($flags & 1) !== 0]);
                    if ($type === 'span' && $block === '') {
                        $block = '{}';
                    }
                    if ($block !== '' && str_ends_with($tail, $block)) {
                        $tail = substr($tail, 0, -strlen($block));
                    }
                    if ($type === 'link' && str_starts_with($tail, ']')) {
                        $leaf->pushString(']', PlanLeaf::CLOSE, $path, $type);
                        $leaf->pushString(substr($tail, 1), PlanLeaf::ATOM, $path, $type);
                    } else {
                        $leaf->pushString($tail, PlanLeaf::CLOSE, $path, $type);
                    }
                    if ($block !== '') {
                        $leaf->pushString($block, PlanLeaf::ATOM, $path, $type);
                    }

                    return;
                }
            }
        }
        $leaf->pushString(
            $part,
            PlanLeaf::ATOM,
            $path,
            $type,
            is_string($node['form'] ?? null) ? $node['form'] : null,
        );
    }

    // phpcs:enable Generic.Metrics.CyclomaticComplexity.MaxExceeded
}
