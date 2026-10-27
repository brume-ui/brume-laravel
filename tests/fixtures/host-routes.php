<?php

use Illuminate\Support\Facades\Route;

Route::get('host-fixture', fn () => 'host ok')->name('brume.host-fixture');
