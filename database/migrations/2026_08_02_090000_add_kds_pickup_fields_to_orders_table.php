<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deliberately separate from picked_up_at/picked_up_by (added by
     * add_porter_pickup_to_orders_table) — those columns already mean
     * something specific: a porter collecting a ROOM order from the pass
     * for delivery, guarded to booking_id orders only (PorterDeliveryService).
     * The KDS board needs its own "collected from the kitchen pass" event
     * for every kitchen order, room or not, without colliding with that.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('kds_picked_up_by')->nullable()->after('picked_up_at')->constrained('users')->nullOnDelete();
            $table->timestamp('kds_picked_up_at')->nullable()->after('kds_picked_up_by');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kds_picked_up_by');
            $table->dropColumn('kds_picked_up_at');
        });
    }
};
