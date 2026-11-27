<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Small input fields demo: one column per field on Grid 示範 items, plus a tiny table behind the
// 驗證碼示範 form (kept apart so the main item form stays submittable without a captcha).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('showcase_items', function (Blueprint $table) {
            $table->string('server_ip', 45)->nullable();
            $table->decimal('discount_rate', 5, 2)->nullable();
            $table->unsignedTinyInteger('volume')->nullable();
            $table->decimal('weight_min', 8, 2)->nullable();
            $table->decimal('weight_max', 8, 2)->nullable();
            $table->string('icon', 64)->nullable();
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->time('reminder_at')->nullable();
            $table->tinyInteger('birth_month')->nullable();
            $table->smallInteger('founded_year')->nullable();
            $table->string('timezone', 64)->nullable();
        });

        Schema::create('captcha_demos', function (Blueprint $table) {
            $table->id();
            $table->string('message');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('captcha_demos');
        Schema::table('showcase_items', function (Blueprint $table) {
            $table->dropColumn(['server_ip', 'discount_rate', 'volume', 'weight_min', 'weight_max', 'icon', 'opens_at', 'closes_at', 'reminder_at', 'birth_month', 'founded_year', 'timezone']);
        });
    }
};
