<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // JSON picker demo: selected catalog ids and their per-item settings, no relation table.
        Schema::table('compositions', function (Blueprint $table) {
            $table->json('picks')->nullable();
        });
    }

    public function down(): void
    {
        // The stored selections and their settings are lost.
        Schema::table('compositions', function (Blueprint $table) {
            $table->dropColumn('picks');
        });
    }
};
