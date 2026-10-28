<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Test-only table behind Brume\Tests\Support\Models\CellItem.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cell_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('shop_id');
            $table->string('customer');
            $table->string('status', 16)->default('open');
            $table->unsignedInteger('amount')->default(0);
            $table->json('tags')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_new')->default(false);
            $table->string('note')->nullable();
            $table->string('password')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cell_items');
    }
};
