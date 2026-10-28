<?php

namespace Brume\Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workbench\App\Models\Order;

/**
 * Rows with a comma-separated column, JSON columns and an order, for the filter tests
 * (tests/database/migrations).
 *
 * @property string|null $tags comma-separated, e.g. '1,12,3'
 * @property array<int, string>|null $labels a JSON array
 * @property array<string, mixed>|null $meta a JSON object
 */
class FilterItem extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['labels' => 'array', 'meta' => 'array'];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
