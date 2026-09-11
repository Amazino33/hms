<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\AttendanceLog;
use App\Models\SalaryDeduction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ZKTecoController extends Controller
{
    /**
     * Handle the handshake / getrequest from ZKTeco
     */
    public function getRequest(Request $request)
    {
        // Device pings this endpoint to check for server commands
        return response("OK");
    }

    /**
     * Handle the attendance data push (cdata)
     */
    public function receiveData(Request $request)
    {
        $data = $request->getContent();
        
        Log::info('ZKTeco raw data: ' . $data);

        // ZKTeco often appends ?SN=... to the URL, but the payload is in the body
        // Data format typically comes in lines like:
        // USER_PIN YYYY-MM-DD HH:MM:SS STATE VERIFY_MODE
        // e.g. "1 2026-09-11 08:05:00 0 1"

        $lines = explode("\n", trim($data));

        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            
            $parts = preg_split('/\s+/', trim($line));
            
            // Expected at least: PIN, Date, Time
            if (count($parts) >= 3) {
                $biometricId = $parts[0];
                $date = $parts[1];
                $time = $parts[2];
                
                try {
                    $punchTime = Carbon::parse($date . ' ' . $time);
                } catch (\Exception $e) {
                    continue; // Skip invalid date strings
                }
                
                $punchState = $parts[3] ?? null; // 0=CheckIn, 1=CheckOut typically
                $verifyMode = $parts[4] ?? null;

                $user = User::where('biometric_id', $biometricId)->first();

                // Save attendance log to database
                AttendanceLog::create([
                    'user_id' => $user?->id,
                    'biometric_id' => $biometricId,
                    'punch_time' => $punchTime,
                    'punch_state' => $punchState,
                    'verify_mode' => $verifyMode,
                ]);

                // Run penalty logic if user exists, has a shift time, and this looks like a check-in
                if ($user && $user->shift_start_time) {
                    $this->checkAndApplyPenalty($user, $punchTime);
                }
            }
        }

        return response("OK");
    }

    /**
     * Check if user is late and apply 500 Naira deduction
     */
    private function checkAndApplyPenalty(User $user, Carbon $punchTime)
    {
        $dateString = $punchTime->toDateString();

        // Ensure we don't deduct multiple times on the same day if they punch twice
        $alreadyDeducted = SalaryDeduction::where('user_id', $user->id)
            ->where('date', $dateString)
            ->where('reason', 'like', 'Late arrival%')
            ->exists();

        if ($alreadyDeducted) {
            return;
        }

        // Combine punch date with their shift time to get exact threshold
        $shiftStart = Carbon::parse($dateString . ' ' . $user->shift_start_time);
        
        if ($punchTime->greaterThan($shiftStart)) {
            SalaryDeduction::create([
                'user_id' => $user->id,
                'amount' => 500.00,
                'date' => $dateString,
                'reason' => 'Late arrival. Expected: ' . $shiftStart->format('H:i') . ', Arrived: ' . $punchTime->format('H:i'),
            ]);
        }
    }
}
