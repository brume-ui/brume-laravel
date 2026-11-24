<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// MySQL DDL must precede the per-test RefreshDatabase transaction.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rich_posts', function ($t) {
            $t->id();
            $t->string('title')->nullable();
            $t->longText('body')->nullable();
            $t->longText('notes')->nullable();
            $t->json('blocks')->nullable();
        });
        Schema::create('rich_sections', function ($t) {
            $t->id();
            $t->unsignedBigInteger('post_id');
            $t->text('body')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rich_sections');
        Schema::dropIfExists('rich_posts');
    }
};
