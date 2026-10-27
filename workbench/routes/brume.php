<?php

// Workbench admin routes. WorkbenchServiceProvider loads this file the way Brume loads a
// project's routes/brume.php: inside the Brume route group, behind brume.auth.

use Brume\Facades\Brume;
use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\AdminUserController;

Route::get('users', AdminUserController::class)->name('brume.users.index');

// Workbench only: flashes the same toast every time, for the "identical flash twice" check.
Route::get('_demo/toast', function () {
    Brume::toast('success', '已儲存');

    return redirect()->back(fallback: route('brume.home'));
})->name('brume.demo.toast');
