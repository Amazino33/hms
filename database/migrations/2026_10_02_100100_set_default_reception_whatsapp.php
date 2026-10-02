<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 5 Step 0.1: the reception WhatsApp number room orders open to.
     * Set ONLY when nothing is there yet — a number the owner has already
     * entered on the Guest Ordering page is never overwritten.
     */
    public const KEY = 'guest_reception_whatsapp';

    public const NUMBER = '2348144734612';

    public function up(): void
    {
        $current = DB::table('settings')->where('key', self::KEY)->value('value');

        if (filled($current)) {
            return;
        }

        DB::table('settings')->updateOrInsert(
            ['key' => self::KEY],
            ['value' => self::NUMBER, 'type' => 'string', 'created_at' => now(), 'updated_at' => now()],
        );

        // SettingsService caches each key for an hour.
        Cache::forget('setting:'.self::KEY);
    }

    public function down(): void
    {
        // Left in place: by now it may be the owner's own setting.
    }
};
