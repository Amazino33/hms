<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            $table->boolean('verified')->default(false)->after('method');
            $table->foreignId('verified_by')->nullable()->after('verified')->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');

            $table->boolean('flagged')->default(false)->after('verified_at');
            $table->string('flag_reason')->nullable()->after('flagged'); // not_found|amount_mismatch|duplicate
            $table->foreignId('flagged_by')->nullable()->after('flag_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('flagged_at')->nullable()->after('flagged_by');

            $table->string('ruling')->nullable()->after('flagged_at'); // late_verify|charge|void
            $table->text('ruling_note')->nullable()->after('ruling');
            $table->foreignId('ruled_by')->nullable()->after('ruling_note')->constrained('users')->nullOnDelete();
            $table->timestamp('ruled_at')->nullable()->after('ruled_by');

            $table->string('payer_reference')->nullable()->after('ruled_at');
        });

        // Cash, POS-terminal, and split (cash+POS only — confirmed no
        // transfer component exists in a split payment at this venue) are
        // all self-evident at collection — only a transfer needs a human
        // to actually check it against the bank/money app.
        \Illuminate\Support\Facades\DB::table('order_payments')
            ->whereIn('method', ['cash', 'pos', 'split'])
            ->update(['verified' => true]);
    }

    public function down(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropConstrainedForeignId('flagged_by');
            $table->dropConstrainedForeignId('ruled_by');
            $table->dropColumn([
                'verified', 'verified_at', 'flagged', 'flag_reason', 'flagged_at',
                'ruling', 'ruling_note', 'ruled_at', 'payer_reference',
            ]);
        });
    }
};
