<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('batch_code')->unique();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->date('catch_date')->nullable();
            $table->date('production_date')->nullable();
            $table->date('expiry_date');
            $table->decimal('quantity_received', 12, 3);
            $table->decimal('quantity_available', 12, 3);
            $table->string('storage_location');
            $table->decimal('unit_cost', 12, 2);
            $table->timestamps();

            $table->index('batch_code');
            $table->index('expiry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};
