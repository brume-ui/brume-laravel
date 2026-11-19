<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nav_menus', function (Blueprint $table) {
            $table->id();
            // A menu with sub-menus cannot be deleted (the form's deleting event explains why).
            $table->foreignId('parent_id')->nullable()->constrained('nav_menus')->restrictOnDelete();
            $table->string('name');
            $table->string('url')->nullable();
            $table->boolean('enabled')->default(true);
            // Position among siblings; NULL sorts last and is numbered on the first save.
            $table->unsignedInteger('order')->nullable();
        });

        Schema::create('home_banners', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('link')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('sort')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_banners');
        Schema::dropIfExists('nav_menus');
    }
};
