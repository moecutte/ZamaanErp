<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->boolean('is_base')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'code']);
            $table->index(['product_id', 'is_base']);
        });

        // Backfill a Whole (base) form for every existing product
        $now = now();
        $products = DB::table('products')->select('id')->get();
        foreach ($products as $product) {
            DB::table('product_forms')->insert([
                'product_id' => $product->id,
                'name' => 'Whole',
                'code' => 'whole',
                'is_base' => true,
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::create('product_processings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_batch_id')->constrained('batches')->cascadeOnDelete();
            $table->decimal('source_quantity', 12, 3);
            $table->decimal('waste_quantity', 12, 3)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('processed_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('product_processing_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_processing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_form_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->foreignId('output_batch_id')->constrained('batches')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::table('batches', function (Blueprint $table) {
            $table->foreignId('product_form_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_forms')
                ->restrictOnDelete();
        });

        // Assign existing batches to each product's base form
        $baseForms = DB::table('product_forms')
            ->where('is_base', true)
            ->pluck('id', 'product_id');

        foreach ($baseForms as $productId => $formId) {
            DB::table('batches')
                ->where('product_id', $productId)
                ->whereNull('product_form_id')
                ->update(['product_form_id' => $formId]);
        }

        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->foreignId('product_form_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_forms')
                ->restrictOnDelete();
        });

        foreach ($baseForms as $productId => $formId) {
            DB::table('sales_order_lines')
                ->where('product_id', $productId)
                ->whereNull('product_form_id')
                ->update(['product_form_id' => $formId]);
        }

        Schema::table('price_list_items', function (Blueprint $table) {
            $table->foreignId('product_form_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_forms')
                ->nullOnDelete();
        });

        Schema::table('customer_price_overrides', function (Blueprint $table) {
            $table->foreignId('product_form_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_forms')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_price_overrides', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_form_id');
        });

        Schema::table('price_list_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_form_id');
        });

        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_form_id');
        });

        Schema::table('batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_form_id');
        });

        Schema::dropIfExists('product_processing_outputs');
        Schema::dropIfExists('product_processings');
        Schema::dropIfExists('product_forms');
    }
};
