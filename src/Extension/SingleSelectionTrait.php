<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

/**
 * The selection step both tab-shaped extensions share (Extensions §13.5).
 *
 * A tab set is SINGLE-SELECT, so exactly one item is selected: the first one
 * the document marks `{selected}`, and the first item where the document marks
 * none. Later marks are ignored.
 *
 * FIRST-WINS, NOT LAST-WINS. The `css` mode is a radio group, and a radio group
 * cannot have two checked members - the browser resolves it to one and the
 * document's intent is already lost. `aria` mode emitting two
 * `aria-selected="true"` tabs is not more expressive, it is a shape a
 * single-select `tablist` has no state for. First-wins is also what the `css`
 * default already does with `checked`, so the two modes agree, which is the
 * whole point of §13 mirroring them. Last-wins would mean an author scrolling a
 * long tab set and marking the item in front of them silently unselects one
 * above.
 *
 * Over-specifying is NOT an error and gets no diagnostic: §13 has no diagnostic
 * channel, and the document is not wrong, only redundant.
 *
 * It lives in a trait rather than in either extension because §13 binds both
 * constructs, and a rule copied into two renderers is a rule that drifts - the
 * exact divergence carve#1468 wrote the mirroring clause to prevent.
 */
trait SingleSelectionTrait
{
    /**
     * Resolve which item of a tab-shaped set is selected.
     *
     * @param array<int, bool> $marked One flag per item, in document order: did the document mark it `{selected}`?
     *
     * @return int The winning index, or -1 when the set is empty.
     */
    protected function resolveSelectedIndex(array $marked): int
    {
        if ($marked === []) {
            return -1;
        }

        $first = array_search(true, $marked, true);

        return $first === false ? 0 : (int)$first;
    }
}
