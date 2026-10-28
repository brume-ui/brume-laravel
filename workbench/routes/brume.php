<?php

// Workbench admin routes. workbench/config/brume.php points brume.routes.host here, so Brume
// loads this file inside its route group, behind brume.auth.

use Brume\Dashboard\Dashboard;
use Brume\Facades\Brume;
use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\AdminUserController;
use Workbench\App\Http\Controllers\OrderController;

Route::get('users', AdminUserController::class)->name('brume.users.index');
Route::get('orders', OrderController::class)->name('brume.orders.index');

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
