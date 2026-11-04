<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            // A parent with sub-categories cannot be deleted (the form's deleting event explains why).
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->restrictOnDelete();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            // Materialized "position.id/" path per ancestor: ordering by it lists the tree depth-first.
            $table->string('tree_path', 1000)->default('');
        });

        Schema::create('catalog_products', function (Blueprint $table) {
            $table->id();
            $table->string('identifier')->unique();
            $table->string('name');
            $table->string('barcode')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_published')->default(false);
            $table->decimal('price', 10, 2)->default(0);
            $table->integer('stock')->default(0);
            $table->string('cover')->nullable();
            $table->json('gallery')->nullable();
            $table->boolean('is_preorder')->default(false);
            $table->dateTime('preorder_ships_at')->nullable();
            $table->string('preorder_note')->nullable();
            // Deleting a category leaves its products uncategorised.
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->boolean('is_bundle')->default(false);
            $table->unsignedInteger('per_order_limit')->nullable();
            $table->unsignedInteger('total_limit')->nullable();
            $table->unsignedInteger('remaining_limit')->nullable();
            $table->unsignedInteger('sold_count')->default(0);
            $table->dateTime('erp_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('catalog_product_components', function (Blueprint $table) {
            // A bundle takes its component rows with it; a component cannot go while a bundle uses it.
            $table->foreignId('bundle_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('catalog_products')->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('position')->default(0);
            $table->primary(['bundle_id', 'component_id']);
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('promotion_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->unsignedInteger('position')->default(0);
            // Soft deletes keep a removed threshold's trashed actions and gift counters attached.
            $table->softDeletes();
        });

        Schema::create('promotion_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condition_id')->constrained('promotion_conditions')->cascadeOnDelete();
            $table->string('strategy');
            $table->unsignedInteger('choose_count')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->softDeletes();
        });

        Schema::create('promotion_freebies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('action_id')->constrained('promotion_actions')->cascadeOnDelete();
            // No foreign key: a removed (trashed) gift row may outlive its product; live gifts block deletion in the form.
            $table->unsignedBigInteger('product_id')->index();
            $table->boolean('is_limited')->default(false);
            $table->unsignedInteger('given_limit')->nullable();
            $table->unsignedInteger('given_count')->default(0);
            $table->unsignedInteger('already_given_count')->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        foreach (['promotion_freebies', 'promotion_actions', 'promotion_conditions', 'promotions', 'catalog_product_components', 'catalog_products', 'product_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
