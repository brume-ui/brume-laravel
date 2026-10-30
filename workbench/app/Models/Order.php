<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Workbench\Database\Factories\OrderFactory;

/**
 * Workbench demo model behind 訂單.
 *
 * @property string $number
 * @property string $customer
 * @property int $amount
 * @property string $status pending|paid|shipped|completed|cancelled
 * @property string $category one of CATEGORIES
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    public const STATUSES = ['pending', 'paid', 'shipped', 'completed', 'cancelled'];

    /** Status labels, as the OrderStatus list cell shows them. */
    public const STATUS_LABELS = [
        'pending' => '待付款',
        'paid' => '已付款',
        'shipped' => '已出貨',
        'completed' => '已完成',
        'cancelled' => '已取消',
    ];

    /** Product categories, for the dashboard's 分類營收 chart. */
    public const CATEGORIES = ['服飾', '家居', '3C 周邊', '美妝', '食品', '運動'];

    protected $attributes = ['amount' => 0, 'status' => 'pending', 'category' => '家居'];

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->number ??= 'BR-'.now()->format('Ymd').'-'.Str::upper((string) Str::ulid());
        });
    }

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }
}
