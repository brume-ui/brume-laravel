<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Test-only tables behind Brume\Tests\Support\Models\TreeNode and TreeCode.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tree_nodes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('shop_id');
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->integer('order')->nullable();
            $table->timestamps();
        });

        Schema::create('tree_codes', function (Blueprint $table) {
            $table->string('code', 32)->primary();
            $table->string('name');
            $table->string('parent_code', 32)->nullable();
            $table->integer('sort')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tree_codes');
        Schema::dropIfExists('tree_nodes');
    }
};
