<?php

namespace Workbench\App\Support;

use Illuminate\Validation\ValidationException;
use Workbench\App\Models\CatalogProduct;

/**
 * The only place that writes total_limit / remaining_limit. It trusts the stored sold_count
 * (the original attribute), never anything the browser sent.
 */
final class ProductPurchaseLimits
{
    public static function apply(CatalogProduct $product, ?int $total): void
    {
        if ($total === null) {
            $product->total_limit = null;
            $product->remaining_limit = null;

            return;
        }

        $sold = (int) $product->getOriginal('sold_count');
        if ($total < 0 || $total < $sold) {
            throw ValidationException::withMessages(['total_limit' => "總限購不可小於已售出的 {$sold} 件"]);
        }

        $product->total_limit = $total;
        $product->remaining_limit = $total - $sold;
    }
}
