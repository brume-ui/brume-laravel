<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brume_uploads', function (Blueprint $table) {
            $table->json('row_context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('brume_uploads', function (Blueprint $table) {
            $table->dropColumn('row_context');
        });
    }
};
