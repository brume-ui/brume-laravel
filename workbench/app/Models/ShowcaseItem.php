<?php

namespace Workbench\App\Models;

use Brume\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workbench\Database\Factories\ShowcaseItemFactory;

/**
 * Workbench demo model behind Grid 示範: a product with a column for every Grid feature.
 *
 * @property string $name
 * @property string $sku
 * @property string $tags comma-separated TAGS keys
 * @property list<string> $channels CHANNELS keys
 * @property array{color: string} $meta
 */
class ShowcaseItem extends Model
{
    /** @use HasFactory<ShowcaseItemFactory> */
    use HasFactory;

    public const CATEGORIES = ['stationery' => '文具', 'kitchen' => '廚房', 'textile' => '布品', 'lighting' => '燈具'];

    /** Same codes as Order::STATUSES; the cell is the registered OrderStatus component. */
    public const STATUSES = ['pending' => '待付款', 'paid' => '已付款', 'shipped' => '已出貨', 'completed' => '已完成', 'cancelled' => '已取消'];

    public const WAREHOUSES = ['taipei' => '台北', 'taichung' => '台中', 'kaohsiung' => '高雄'];

    public const RATINGS = [1 => '1 星', 2 => '2 星', 3 => '3 星', 4 => '4 星', 5 => '5 星'];

    public const TAGS = ['gift' => '禮品', 'eco' => '環保', 'limited' => '限量', 'handmade' => '手作'];

    public const CHANNELS = ['web' => '官網', 'store' => '門市', 'app' => 'App'];

    /** meta->color values and the swatch color the image route draws. */
    public const COLORS = [
        'teal' => ['青', '#0e7490'],
        'sand' => ['沙', '#b08d57'],
        'slate' => ['灰', '#64748b'],
        'rose' => ['玫瑰', '#be4b62'],
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'stock' => 'integer',
            'progress' => 'integer',
            'rating' => 'integer',
            'views' => 'integer',
            'channels' => 'array',
            'meta' => 'array',
            'images' => 'array',
            'attachments' => 'array',
            'variants' => 'array',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_new' => 'boolean',
            'released_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    protected static function newFactory(): ShowcaseItemFactory
    {
        return ShowcaseItemFactory::new();
    }
}
