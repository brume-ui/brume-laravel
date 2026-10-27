<?php

// Workbench admin routes. workbench/config/brume.php points brume.routes.host here, so Brume
// loads this file inside its route group, behind brume.auth.

use Brume\Facades\Brume;
use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\AdminUserController;

Route::get('users', AdminUserController::class)->name('brume.users.index');

// Workbench only: flashes the same toast every time, for the "identical flash twice" check.
Route::get('_demo/toast', function () {
    Brume::toast('success', '已儲存');

    return redirect()->back(fallback: route('brume.home'));
})->name('brume.demo.toast');
