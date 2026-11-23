<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 商品 rich text demo: FAQ rows (a JSON repeater whose answers are editors) and the read-only ERP
// note. The long description reuses the existing `description` text column.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->json('faqs')->nullable();
            $table->text('erp_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            $table->dropColumn(['faqs', 'erp_note']);
        });
    }
};
