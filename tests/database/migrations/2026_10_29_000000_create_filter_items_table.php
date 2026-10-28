<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Test-only table behind Brume\Tests\Support\Models\FilterItem.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filter_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable();
            $table->string('tags')->nullable();
            $table->json('labels')->nullable();
            $table->json('meta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filter_items');
    }
};
