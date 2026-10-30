<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brume_uploads', function (Blueprint $table) {
            $table->id();
            $table->string('token', 40)->unique();
            $table->string('form');
            $table->string('field');
            $table->string('user_id')->nullable();
            $table->string('guard');
            $table->string('record')->nullable();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime');
            $table->unsignedBigInteger('size');
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brume_uploads');
    }
};
