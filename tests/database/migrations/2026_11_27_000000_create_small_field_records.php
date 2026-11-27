<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// MySQL DDL must precede the per-test RefreshDatabase transaction.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('small_posts', function ($t) {
            $t->id();
            $t->string('address')->nullable();
            $t->decimal('share', 8, 2)->nullable();
            $t->decimal('level', 8, 3)->nullable();
            $t->decimal('low', 10, 3)->nullable();
            $t->decimal('high', 10, 3)->nullable();
            $t->string('glyph')->nullable();
            $t->time('opens')->nullable();
            $t->time('closes')->nullable();
            $t->json('blocks')->nullable();
        });
        Schema::create('small_rows', function ($t) {
            $t->id();
            $t->unsignedBigInteger('post_id');
            $t->decimal('low', 10, 3)->nullable();
            $t->decimal('high', 10, 3)->nullable();
            $t->time('opens')->nullable();
            $t->time('closes')->nullable();
            $t->string('address')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('small_rows');
        Schema::dropIfExists('small_posts');
    }
};
