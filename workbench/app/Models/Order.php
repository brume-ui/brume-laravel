<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Workbench\Database\Factories\OrderFactory;

/**
 * Workbench demo model behind 訂單.
 *
 * @property string $number
 * @property string $customer
 * @property int $amount
 * @property string $status pending|paid|shipped|completed|cancelled
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    public const STATUSES = ['pending', 'paid', 'shipped', 'completed', 'cancelled'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }
}
