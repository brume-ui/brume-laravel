<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compositions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('category')->default('A');
            $table->boolean('delivery')->default(false);
            $table->string('delivery_note')->nullable();
            foreach (['zipcode', 'county', 'district', 'address'] as $attribute) {
                $table->string($attribute)->nullable();
            }
            $table->json('notes')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
        });
        Schema::create('composition_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category');
            $table->string('sku');
            $table->string('note')->nullable();
            $table->unsignedBigInteger('composition_id')->nullable();
        });
        Schema::create('composition_featured', function (Blueprint $table) {
            $table->unsignedBigInteger('composition_id');
            $table->unsignedBigInteger('catalog_id');
            $table->integer('quantity')->default(1);
            $table->integer('position')->default(0);
            $table->primary(['composition_id', 'catalog_id']);
        });
        Schema::create('composition_nodes', function (Blueprint $table) {
            $table->id();
            // A top-level tier binds composition_id; descendants bind parent_id instead.
            $table->unsignedBigInteger('composition_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->integer('position')->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->json('notes')->nullable();
            $table->string('attachment')->nullable();
        });
        Schema::create('composition_gifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('node_id');
            $table->unsignedBigInteger('catalog_id');
            $table->integer('quantity')->default(1);
            $table->integer('position')->default(0);
            $table->integer('counter')->default(37);
            $table->string('attachment')->nullable();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        foreach (['composition_gifts', 'composition_nodes', 'composition_featured', 'composition_catalog', 'compositions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
