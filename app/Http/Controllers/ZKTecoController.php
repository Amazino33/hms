<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\SalaryDeduction;
use App\Models\User;
use App\Support\VenueTime;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Speaks the ZKTeco ADMS ("iclock" push) protocol to a K20 Pro terminal.
 *
 * The device drives the whole conversation — it polls us, we never dial it.
 * Four endpoints, in the order the terminal uses them:
 *
 *   1. GET  /iclock/cdata?SN=..&options=all   handshake; must return the
 *      options block below or the device shows a red X and stops. This is
 *      the request that turns the server icon green.
 *   2. GET  /iclock/getrequest?SN=..          command poll, every Delay seconds.
 *   3. POST /iclock/cdata?SN=..&table=ATTLOG  the actual punches.
 *   4. POST /iclock/devicecmd?SN=..           acknowledgement of a command.
 *
 * Every response is plain text — the firmware parses bytes, not JSON, and a
 * stray HTML error page reads to it as a transport failure.
 */
class ZKTecoController extends Controller
{
    /**
     * The late-arrival penalty, in Naira.
     */
    private const LATE_PENALTY = 500.00;

    /**
     * Step 1 — handshake. The device asks for its orders before it will talk
     * to us at all, and judges the connection purely on getting a 200 with a
     * parseable options block back.
     */
    public function handshake(Request $request): Response
    {
        $serial = $request->query('SN', 'UNKNOWN');

        Log::info('ZKTeco handshake from SN: '.$serial);

        // TimeZone=1 tells the terminal our clock is UTC+1, matching Lagos,
        // so the timestamps it stamps on punches line up with what
        // storePunch() expects to parse.
        //
        // TransFlag's ten digits each toggle one upload category (attendance,
        // operation log, photos, enrolments...). The stock 1111000000 is the
        // most widely compatible value; we let the extra tables arrive and
        // ignore them in receiveData() rather than risk a firmware that sulks
        // at a narrower flag.
        $options = implode("\r\n", [
            'GET OPTION FROM: '.$serial,
            'Stamp=9999',
            'OpStamp=9999',
            'ErrorDelay=30',
            'Delay=10',
            'TransTimes=00:00;14:00',
            'TransInterval=1',
            'TransFlag=1111000000',
            'TimeZone=1',
            'Realtime=1',
            'Encrypt=0',
        ])."\r\n";

        return $this->plainText($options);
    }

    /**
     * Step 2 — command poll. We queue nothing at the terminal today, so a
     * bare OK is the correct "nothing for you" answer. Anything else here
     * would be read as a command and fail to parse.
     */
    public function getRequest(Request $request): Response
    {
        return $this->plainText('OK');
    }

    /**
     * Step 3 — the attendance push.
     *
     * The same URL carries several tables. Only ATTLOG is punch data;
     * OPERLOG (menu operations), USERINFO and the fingerprint-template
     * tables all arrive here too and must not be parsed as punches, or every
     * admin who opens a menu on the device books an attendance row and,
     * worse, a 500 Naira deduction.
     */
    public function receiveData(Request $request): Response
    {
        $table = strtoupper($request->query('table', 'ATTLOG'));
        $body = $request->getContent();

        Log::info('ZKTeco push', ['table' => $table, 'sn' => $request->query('SN'), 'body' => $body]);

        if ($table !== 'ATTLOG') {
            // Acknowledged and deliberately discarded — the device retries
            // anything it does not see accepted.
            return $this->plainText('OK');
        }

        $saved = 0;

        foreach (preg_split('/\r\n|\r|\n/', trim($body)) as $line) {
            if (trim($line) === '') {
                continue;
            }

            if ($this->storePunch($line)) {
                $saved++;
            }
        }

        // The firmware expects the accepted-record count; without it the
        // batch is treated as unconfirmed and re-sent on the next cycle.
        return $this->plainText('OK: '.$saved);
    }

    /**
     * Step 4 — the device reporting the result of a command it ran. Nothing
     * to act on yet, but the endpoint must exist and return OK or the
     * terminal logs a transport error and backs off.
     */
    public function deviceCommand(Request $request): Response
    {
        Log::info('ZKTeco devicecmd', ['sn' => $request->query('SN'), 'body' => $request->getContent()]);

        return $this->plainText('OK');
    }

    /**
     * One ATTLOG record, returning whether it was accepted.
     */
    private function storePunch(string $line): bool
    {
        [$biometricId, $rawTimestamp, $punchState, $verifyMode] = $this->parseLine($line);

        if ($biometricId === null || $rawTimestamp === null) {
            Log::warning('ZKTeco unparseable ATTLOG line: '.$line);

            return false;
        }

        // The terminal stamps punches in its own wall-clock time, which is
        // Lagos. Parsing that as UTC (the app default) would bank every punch
        // an hour early, and the display layer would then add its own hour on
        // the way out. Parse local, store UTC — see App\Support\VenueTime.
        try {
            $punchLocal = Carbon::createFromFormat('Y-m-d H:i:s', $rawTimestamp, VenueTime::TIMEZONE);
        } catch (\Exception $e) {
            Log::warning('ZKTeco bad timestamp "'.$rawTimestamp.'" in line: '.$line);

            return false;
        }

        $punchUtc = $punchLocal->copy()->utc();

        // The device re-sends a whole batch whenever it does not see its count
        // acknowledged, so the same punch legitimately arrives more than once.
        $alreadyLogged = AttendanceLog::where('biometric_id', $biometricId)
            ->where('punch_time', $punchUtc)
            ->exists();

        if ($alreadyLogged) {
            return true; // Counts as accepted, or the device keeps retrying.
        }

        $user = User::where('biometric_id', $biometricId)->first();

        AttendanceLog::create([
            'user_id' => $user?->id,
            'biometric_id' => $biometricId,
            'punch_time' => $punchUtc,
            'punch_state' => $punchState,
            'verify_mode' => $verifyMode,
        ]);

        if ($user && $user->shift_start_time) {
            $this->checkAndApplyPenalty($user, $punchLocal);
        }

        return true;
    }

    /**
     * ATTLOG is tab-separated in the spec:
     *
     *   PIN \t YYYY-MM-DD HH:MM:SS \t STATUS \t VERIFY \t WORKCODE \t ...
     *
     * Some firmware revisions pad with spaces instead, so we fall back to
     * splitting on any whitespace — the datetime's own internal space means
     * that path yields [PIN, date, time, status, verify] and has to be
     * recombined.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string}
     */
    private function parseLine(string $line): array
    {
        $none = [null, null, null, null];
        $line = trim($line);

        if (str_contains($line, "\t")) {
            $parts = array_map('trim', explode("\t", $line));

            return count($parts) >= 2
                ? [$parts[0], $parts[1], $parts[2] ?? null, $parts[3] ?? null]
                : $none;
        }

        $parts = preg_split('/\s+/', $line);

        return count($parts) >= 3
            ? [$parts[0], $parts[1].' '.$parts[2], $parts[3] ?? null, $parts[4] ?? null]
            : $none;
    }

    /**
     * Lateness is a wall-clock question, so both sides of this comparison are
     * Lagos-local: the punch as the staff member experienced it, against the
     * shift start time as configured on their profile.
     */
    private function checkAndApplyPenalty(User $user, Carbon $punchLocal): void
    {
        $localDate = $punchLocal->toDateString();

        // One penalty per person per day, however many times they punch.
        $alreadyDeducted = SalaryDeduction::where('user_id', $user->id)
            ->where('date', $localDate)
            ->where('reason', 'like', 'Late arrival%')
            ->exists();

        if ($alreadyDeducted) {
            return;
        }

        $shiftStart = Carbon::createFromFormat(
            'Y-m-d H:i:s',
            $localDate.' '.$user->shift_start_time,
            VenueTime::TIMEZONE
        );

        if ($punchLocal->lessThanOrEqualTo($shiftStart)) {
            return;
        }

        SalaryDeduction::create([
            'user_id' => $user->id,
            'amount' => self::LATE_PENALTY,
            'date' => $localDate,
            'reason' => 'Late arrival. Expected: '.$shiftStart->format('H:i').', Arrived: '.$punchLocal->format('H:i'),
        ]);
    }

    private function plainText(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
