<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// MySQL DDL must precede the per-test RefreshDatabase transaction.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('graph_roots', function ($t) {
            $t->id();
            $t->string('name')->default('root');
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->integer('sold')->default(0);
            $t->string('code')->nullable();
            $t->json('codes')->nullable();
            $t->json('data')->nullable();
        });
        Schema::create('graph_children', function ($t) {
            $t->id();
            $t->unsignedBigInteger('root_id')->nullable();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('name')->default('child');
            $t->integer('sold')->default(0);
            $t->integer('position')->default(0);
            $t->json('data')->nullable();
        });
        Schema::create('graph_products', function ($t) {
            $t->id();
            $t->unsignedBigInteger('root_id')->nullable();
            $t->string('name')->default('product');
            $t->integer('tenant')->default(1);
            $t->boolean('active')->default(true);
            $t->json('data')->nullable();
            $t->json('stored')->nullable();
        });
        Schema::create('graph_gifts', function ($t) {
            $t->id();
            $t->unsignedBigInteger('root_id');
            $t->unsignedBigInteger('product_id');
            $t->integer('quantity')->default(1);
            $t->integer('sold')->default(0);
            $t->softDeletes();
        });
        Schema::create('graph_components', function ($t) {
            $t->unsignedBigInteger('root_id');
            $t->unsignedBigInteger('product_id');
            $t->integer('quantity')->default(1);
            $t->integer('sold')->default(0);
            $t->primary(['root_id', 'product_id']);
        });
        Schema::create('graph_alternate_components', function ($table) {
            $table->unsignedBigInteger('root_id');
            $table->string('product_code');
            $table->integer('quantity')->default(1);
            $table->integer('sold')->default(0);
            $table->primary(['root_id', 'product_code']);
        });
        Schema::create('graph_string_components', function ($table) {
            $table->unsignedBigInteger('root_id');
            $table->string('product_code');
            $table->integer('quantity')->default(1);
            $table->integer('sold')->default(0);
            $table->primary(['root_id', 'product_code']);
        });
    }

    public function down(): void
    {
        foreach (['graph_string_components', 'graph_alternate_components', 'graph_components', 'graph_gifts', 'graph_products', 'graph_children', 'graph_roots'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
