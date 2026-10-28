<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Test-only table behind Brume\Tests\Support\Models\ActionItem.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('shop_id');
            $table->string('name');
            $table->string('status', 16)->default('open');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_items');
    }
};
