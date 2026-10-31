<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('composition_nodes', function (Blueprint $table) {
            $table->string('gift_type')->default('product');
            $table->string('coupon_code')->nullable();
            $table->decimal('coupon_amount', 12, 2)->nullable();
            $table->dateTime('coupon_expires_at')->nullable();
            $table->integer('points')->nullable();
            $table->text('points_note')->nullable();
        });
    }

    public function down(): void
    {
        // Only use down on an isolated fixture or after backing up these settings.
        // Existing nodes, gifts, counters and attachments remain; new settings are lost.
        Schema::table('composition_nodes', function (Blueprint $table) {
            $table->dropColumn(['gift_type', 'coupon_code', 'coupon_amount', 'coupon_expires_at', 'points', 'points_note']);
        });
    }
};
