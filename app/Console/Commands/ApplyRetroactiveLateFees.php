<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\DailyAttendance;
use App\Models\SalaryDeduction;
use Carbon\Carbon;
use App\Support\VenueTime;

class ApplyRetroactiveLateFees extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:apply-retroactive-late-fees';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scans existing attendance records and applies late fees if missing';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Scanning existing records for missing lateness fees...");
        
        $attendances = DailyAttendance::with('user')->get();
        $added = 0;

        foreach ($attendances as $att) {
            if (!$att->user || !$att->user->shift_start_time) {
                continue; // Skip users without a shift start time or mapping
            }

            $punchLocal = Carbon::parse($att->first_punch)->timezone(VenueTime::TIMEZONE);
            $dateStr = $att->date instanceof \Carbon\Carbon ? $att->date->toDateString() : \Carbon\Carbon::parse($att->date)->toDateString();
            $shiftStart = Carbon::parse($dateStr . ' ' . $att->user->shift_start_time, VenueTime::TIMEZONE);

            if ($punchLocal->greaterThan($shiftStart)) {
                $alreadyDeducted = SalaryDeduction::where('user_id', $att->user_id)
                    ->where('date', $att->date)
                    ->where(function($q) {
                        $q->where('reason', 'like', 'Late arrival%')
                          ->orWhere('reason', 'like', 'Lateness fee%');
                    })
                    ->exists();

                if (!$alreadyDeducted) {
                    SalaryDeduction::create([
                        'user_id' => $att->user_id,
                        'amount' => 500.00,
                        'date' => $att->date,
                        'reason' => 'Lateness fee. Expected: '.$shiftStart->format('H:i').', Arrived: '.$punchLocal->format('H:i'),
                    ]);
                    $added++;
                    $this->line("Added 500 NGN fee for user ID {$att->user_id} on {$att->date}");
                }
            }
        }
        
        $this->info("Finished! Added $added new lateness fees.");
    }
}
