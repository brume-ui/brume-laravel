<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Test-only tables behind Brume\Tests\Support\Models\SortItem and SortCode.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sort_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('shop_id');
            $table->string('name');
            $table->integer('sort')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sort_codes', function (Blueprint $table) {
            $table->string('code', 32)->primary();
            $table->string('name');
            $table->integer('sort')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sort_codes');
        Schema::dropIfExists('sort_items');
    }
};
