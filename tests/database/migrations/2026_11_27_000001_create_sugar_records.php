<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sugar_posts', function ($t) {
            $t->id();
            $t->string('title')->nullable();
            $t->unsignedTinyInteger('birth_month')->nullable();
            $t->unsignedSmallInteger('founded_year')->nullable();
            $t->string('zone')->nullable();
            $t->string('month_text', 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sugar_posts');
    }
};
