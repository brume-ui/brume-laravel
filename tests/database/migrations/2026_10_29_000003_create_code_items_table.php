<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Test-only table behind Brume\Tests\Support\Models\CodeItem.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_items', function (Blueprint $table) {
            // MySQL's default collation compares this key case-insensitively; SQLite's does not.
            $table->string('code', 32)->primary();
            $table->string('name');
            // MySQL returns a decimal column as a string, SQLite as a float.
            $table->decimal('price', 8, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('code_items');
    }
};
