<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->text('note')->nullable());
        Schema::table('showcase_items', function (Blueprint $table) {
            $table->string('cover')->nullable();
            $table->json('gallery')->nullable();
            $table->json('documents')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_mobile')->nullable();
            $table->string('website')->nullable();
            $table->string('secret_note')->nullable();
            $table->decimal('price', 10, 2)->change();
            $table->decimal('rating', 3, 1)->change();
        });
        // Upgrade existing demo CSV values before the model starts using an array cast.
        DB::table('showcase_items')->orderBy('id')->each(function ($item) {
            DB::table('showcase_items')->where('id', $item->id)->update(['tags' => json_encode(array_values(array_filter(explode(',', $item->tags))))]);
        });
        Schema::table('showcase_items', fn (Blueprint $table) => $table->json('tags')->change());
    }

    public function down(): void
    {
        Schema::table('showcase_items', fn (Blueprint $table) => $table->text('tags')->change());
        DB::table('showcase_items')->orderBy('id')->each(function ($item) {
            DB::table('showcase_items')->where('id', $item->id)->update(['tags' => implode(',', json_decode($item->tags, true) ?? [])]);
        });
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('note'));
        Schema::table('showcase_items', function (Blueprint $table) {
            $table->dropColumn(['cover', 'gallery', 'documents', 'starts_at', 'ends_at', 'contact_email', 'contact_mobile', 'website', 'secret_note']);
            $table->string('tags')->change();
            $table->unsignedInteger('price')->change();
            $table->unsignedTinyInteger('rating')->change();
        });
    }
};
