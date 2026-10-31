<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// Composite-form HTTP rollback tests need real MySQL savepoints, not in-transaction DDL.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taiwan_addresses', function ($table) {
            $table->id();
            foreach (['zipcode', 'county', 'district', 'address'] as $attribute) {
                $table->string($attribute);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taiwan_addresses');
    }
};
