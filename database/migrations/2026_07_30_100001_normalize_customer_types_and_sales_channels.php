<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Customer types: individual → household, wholesale → retailer
        DB::table('customers')->where('type', 'individual')->update(['type' => 'household']);
        DB::table('customers')->where('type', 'wholesale')->update(['type' => 'retailer']);

        DB::table('pricing_tiers')->where('customer_type', 'individual')->update(['customer_type' => 'household']);
        DB::table('pricing_tiers')->where('customer_type', 'wholesale')->update(['customer_type' => 'retailer']);

        // Channels: retail_pos → pos; restaurant/wholesale → sales_order
        DB::table('sales_orders')->where('channel', 'retail_pos')->update(['channel' => 'pos']);
        DB::table('sales_orders')->whereIn('channel', ['restaurant', 'wholesale'])->update(['channel' => 'sales_order']);
    }

    public function down(): void
    {
        DB::table('customers')->where('type', 'household')->update(['type' => 'individual']);
        DB::table('customers')->where('type', 'retailer')->update(['type' => 'wholesale']);

        DB::table('pricing_tiers')->where('customer_type', 'household')->update(['customer_type' => 'individual']);
        DB::table('pricing_tiers')->where('customer_type', 'retailer')->update(['customer_type' => 'wholesale']);

        DB::table('sales_orders')->where('channel', 'pos')->update(['channel' => 'retail_pos']);
        // Cannot restore restaurant vs wholesale distinction for sales_orders
        DB::table('sales_orders')->where('channel', 'sales_order')->update(['channel' => 'restaurant']);
    }
};
