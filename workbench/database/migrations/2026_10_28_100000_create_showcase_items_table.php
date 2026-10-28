<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Workbench demo data (Grid 示範): one column per Grid display, editor and filter kind.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('showcase_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sku')->unique();
            $table->string('slogan');
            $table->string('note')->nullable();
            $table->text('description');
            $table->string('category', 16);
            $table->string('status', 16);
            $table->string('warehouse', 16);
            // Whole New Taiwan dollars.
            $table->unsignedInteger('price');
            $table->unsignedInteger('stock');
            $table->unsignedTinyInteger('progress');
            $table->unsignedTinyInteger('rating');
            $table->unsignedInteger('views');
            // Comma-separated, no spaces: the findInSet filter.
            $table->string('tags');
            // JSON array: the checkbox editor and the in()->json() filter.
            $table->json('channels');
            // { color, ... }: the JSON path filter meta->color.
            $table->json('meta');
            $table->json('images');
            $table->json('attachments');
            $table->json('variants');
            $table->boolean('is_active');
            $table->boolean('is_featured');
            $table->boolean('is_new');
            // A brume_users admin, shown through the owner relation.
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->date('released_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('showcase_items');
    }
};
