<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Color / tel / html demo: a label colour on 商品 (#rrggbb) and a contact telephone on Grid 示範 items.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->string('label_color', 9)->nullable();
        });
        Schema::table('showcase_items', function (Blueprint $table) {
            $table->string('contact_tel')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->dropColumn('label_color');
        });
        Schema::table('showcase_items', function (Blueprint $table) {
            $table->dropColumn('contact_tel');
        });
    }
};
