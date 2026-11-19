<?php

namespace Brume\Grid\Ordering;

use RuntimeException;

/**
 * The rows a reorder request names are no longer what the grid's query holds (one was deleted,
 * added or moved out of it): answered with a 409 so the page reloads instead of saving a partial
 * order.
 *
 * @internal
 */
final class ReorderConflict extends RuntimeException
{
    public static function make(): self
    {
        return new self(__('brume::grid.reorder.conflict'));
    }
}
