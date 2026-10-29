<?php

// Your Brume admin routes. Brume loads this file inside its route group: every route here
// already has the prefix and middleware from config/brume.php and requires a signed-in admin.
//
// A list: the controller returns a Brume\Grid\Grid. Route::brumeGrid registers the page plus the
// endpoints its actions, inline editing, export and remote filters use (see the README's Grid).
//
// use App\Http\Controllers\Admin\OrderController;
// use Illuminate\Support\Facades\Route;
//
// Route::brumeGrid('orders', OrderController::class)->name('brume.orders.index');
