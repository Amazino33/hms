<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5 — guest ordering from rooms. A room request belongs to the
     * stay (the checked-in booking), is approved by reception, and is
     * delivered by a porter. Every charge goes to the folio.
     *
     * guest_trusted_devices, guest_contacts and guest_delivery_refusals are
     * append-only; each changes only through its own service (see models).
     */
    public function up(): void
    {
        Schema::table('guest_requests', function (Blueprint $table) {
            $table->foreignId('stay_id')->nullable()->after('room_id')->constrained('bookings')->nullOnDelete();
            $table->string('channel', 10)->nullable()->after('source'); // whatsapp | none (rooms)
            $table->boolean('first_from_device')->default(false)->after('device_id');
            $table->index(['stay_id', 'device_id']);
        });

        Schema::table('guest_request_items', function (Blueprint $table) {
            // awaiting_dispatch | out_for_delivery | delivered | refused (rooms only)
            $table->string('delivery_status', 20)->nullable()->after('status');
            $table->foreignId('porter_user_id')->nullable()->after('delivery_status')->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable()->after('porter_user_id');
            $table->timestamp('delivered_at')->nullable()->after('dispatched_at');
        });

        Schema::create('guest_trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stay_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('device_id', 32);
            $table->foreignId('first_approved_request_id')->constrained('guest_requests');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['stay_id', 'device_id']);
        });

        Schema::create('guest_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stay_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('phone', 15); // E.164 digits, e.g. 2348012345678
            $table->string('source', 20)->default('whatsapp_order');
            $table->boolean('marketing_opt_in')->default(false);
            $table->timestamp('opted_in_at')->nullable();
            $table->foreignId('recorded_by_user_id')->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['stay_id', 'phone']);
            $table->index('marketing_opt_in');
        });

        Schema::create('guest_delivery_refusals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained();
            $table->json('line_ids');
            $table->string('station', 10); // bar | kitchen
            $table->string('reason');
            $table->foreignId('recorded_by_user_id')->constrained('users');
            $table->foreignId('bar_return_confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('bar_return_confirmed_at')->nullable();
            // awaiting_bar_return | awaiting_manager | approved | rejected
            $table->string('status', 20);
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('status');
        });

        Schema::table('guest_waiter_calls', function (Blueprint $table) {
            // D28: a room calls reception — table_id stays empty then.
            $table->foreignId('room_id')->nullable()->after('table_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('table_id')->nullable()->change();
        });

        Schema::table('guest_payment_claims', function (Blueprint $table) {
            // A room claim belongs to the stay, not a table sitting.
            $table->foreignId('stay_id')->nullable()->after('guest_table_session_id')->constrained('bookings')->cascadeOnDelete();
            $table->unsignedBigInteger('guest_table_session_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('guest_payment_claims', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stay_id');
        });

        Schema::table('guest_waiter_calls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_id');
        });

        Schema::dropIfExists('guest_delivery_refusals');
        Schema::dropIfExists('guest_contacts');
        Schema::dropIfExists('guest_trusted_devices');

        Schema::table('guest_request_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('porter_user_id');
            $table->dropColumn(['delivery_status', 'dispatched_at', 'delivered_at']);
        });

        Schema::table('guest_requests', function (Blueprint $table) {
            $table->dropIndex(['stay_id', 'device_id']);
            $table->dropConstrainedForeignId('stay_id');
            $table->dropColumn(['channel', 'first_from_device']);
        });
    }
};
