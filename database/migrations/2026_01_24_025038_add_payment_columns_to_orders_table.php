<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // We track what they actually paid vs the total
            $table->decimal('amount_paid', 10, 2)->default(0)->after('total_amount');
            
            // We track the payment method for the *paid portion*
            $table->string('payment_method')->nullable(); // Ensure this exists
            
            // Optional: Link to a customer profile for debt tracking
            if (!Schema::hasColumn('orders', 'guest_id')) { // Rename from customer_id to guest_id
                $table->foreignId('guest_id') // clearer name
                    ->nullable()
                    ->after('user_id')
                    ->constrained('guests') // Point to your existing guests table
                    ->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('amount_paid');
            $table->dropForeign(['guest_id']);
            $table->dropColumn('guest_id');
            $table->dropColumn('payment_method');
        });
    }
};
