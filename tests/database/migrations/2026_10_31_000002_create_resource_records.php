<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('shop_id')->default(1);
            $table->string('title')->nullable();
            $table->string('file')->nullable();
            $table->json('meta')->nullable();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_records');
    }
};
