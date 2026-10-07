<?php

namespace Database\Seeders;

use App\Models\Attendance\AttendanceShiftTemplate;
use App\Models\User;
use App\Services\Attendance\AttendanceOnlyRoleService;
use Illuminate\Database\Seeder;

/**
 * The two patterns that already exist in the venue, plus the attendance-only
 * role.
 *
 * Deliberately no afternoon shift: the admin creates that one from the form,
 * which is the point at which they find out whether the form is usable.
 *
 * Safe to re-run — firstOrCreate on the name, so a deploy never duplicates a
 * template or disturbs one whose times have since been corrected.
 */
class AttendanceShiftTemplateSeeder extends Seeder
{
    public function run(): void
    {
        // Zero permissions, by design. The role exists to be held, not to
        // grant: User::canAccessPanel() denies it explicitly.
        AttendanceOnlyRoleService::role();

        AttendanceShiftTemplate::firstOrCreate(
            ['name' => 'Day shift'],
            [
                'start_time' => '08:00:00',
                'duration_minutes' => 600,
                'pattern_type' => 'weekly',
                'weekly_days' => [1, 2, 3, 4, 5, 6, 7],
                'is_handover' => false,
            ],
        );

        AttendanceShiftTemplate::firstOrCreate(
            ['name' => 'Bartender 24h'],
            [
                'start_time' => '08:00:00',
                // A full day: 08:00 to 08:00 the next morning, which is why
                // the schema stores duration rather than an end time.
                'duration_minutes' => 1440,
                'pattern_type' => 'rotation',
                'rotation_on_days' => 1,
                'rotation_off_days' => 1,
                // Whoever is on is relieving the person who worked the day
                // before, so arriving late strands them.
                'is_handover' => true,
            ],
        );
    }
}
