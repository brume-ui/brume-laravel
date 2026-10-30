<?php

// Workbench admin routes. workbench/config/brume.php points brume.routes.host here, so Brume
// loads this file inside its route group, behind brume.auth.

use Brume\Dashboard\Dashboard;
use Brume\Facades\Brume;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\AdminUserController;
use Workbench\App\Http\Controllers\GridShowcaseController;
use Workbench\App\Http\Controllers\OrderController;
use Workbench\App\Models\ShowcaseItem;

Route::get('users', AdminUserController::class)->name('brume.users.index');
Route::brumeResource('orders', OrderController::class)->names('brume.orders');
Route::brumeResource('grid-showcase', GridShowcaseController::class)->names('brume.grid-showcase');

// 元件展示: one registered component (ComponentShowcase.tsx) that renders the whole brume-react kit.
Route::get('components', fn () => Dashboard::make('元件展示')
    ->component('ComponentShowcase', ['dashboardUrl' => route('brume.home')]))
    ->name('brume.components');

// Workbench only: flashes the same toast every time, for the "identical flash twice" check.
Route::get('_demo/toast', function () {
    Brume::toast('success', '已儲存');

    return redirect()->back(fallback: route('brume.home'));
})->name('brume.demo.toast');

// Workbench only (e2e, not in the menu): a component name nobody registered, to check the
// guidance shown instead of an empty slot.
Route::get('_demo/missing-component', fn () => Dashboard::make('缺少元件')->component('not-registered'))
    ->name('brume.demo.missing-component');

// Workbench only: the color swatches Grid 示範 shows as images and offers as downloads (no
// network needed). ?download=1 sends it as an attachment.
Route::get('_demo/swatch/{color}.svg', function (Request $request, string $color) {
    abort_unless(isset(ShowcaseItem::COLORS[$color]), 404);

    [$label, $hex] = ShowcaseItem::COLORS[$color];
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
        .'<title>'.e($label).'</title><rect width="64" height="64" rx="12" fill="'.$hex.'"/></svg>';

    return response($svg, 200, ['Content-Type' => 'image/svg+xml']
        + ($request->boolean('download') ? ['Content-Disposition' => 'attachment; filename="swatch-'.$color.'.svg"'] : []));
})->where('color', '[a-z]+')->name('brume.demo.swatch');
