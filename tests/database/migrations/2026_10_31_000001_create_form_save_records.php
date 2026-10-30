<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Test-only schema must precede RefreshDatabase's per-test transaction.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_save_records', function (Blueprint $table) {
            $table->id();
            $table->json('meta')->nullable();
            $table->string('starts_at')->nullable();
            $table->string('ends_at')->nullable();
            $table->string('amount')->nullable();
            $table->string('file')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_save_records');
    }
};
