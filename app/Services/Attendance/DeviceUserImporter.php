<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceDeviceUser;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Imports the terminal's user list from a CSV or XLSX export.
 *
 * The device is not reachable from the hosted server, so Phase 1 takes the
 * list by hand. Upsert by device_user_id and never by name — the names on the
 * device are shortened and sometimes duplicated, and matching on them would
 * merge two people into one badge.
 *
 * Retired badges are reported as skipped and never written: retirement says
 * an ID is finished with, and the device keeps exporting IDs it has since
 * reassigned.
 */
class DeviceUserImporter
{
    /**
     * @return array{new: int, renamed: int, unchanged: int, skipped_retired: int, errors: array<int, string>}
     *
     * @throws ValidationException
     */
    public function import(string $path): array
    {
        $rows = $this->readRows($path);

        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => 'That file has no rows in it.',
            ]);
        }

        $header = $this->normaliseHeader(array_shift($rows));

        foreach (['device_user_id', 'device_name'] as $required) {
            if (! in_array($required, $header, true)) {
                throw ValidationException::withMessages([
                    'file' => 'The file needs a "'.$required.'" column. Found: '.implode(', ', $header).'.',
                ]);
            }
        }

        $idIndex = array_search('device_user_id', $header, true);
        $nameIndex = array_search('device_name', $header, true);

        $result = ['new' => 0, 'renamed' => 0, 'unchanged' => 0, 'skipped_retired' => 0, 'errors' => []];

        foreach ($rows as $lineNumber => $row) {
            $deviceUserId = trim((string) ($row[$idIndex] ?? ''));
            $deviceName = trim((string) ($row[$nameIndex] ?? ''));

            if ($deviceUserId === '') {
                // A trailing blank line is normal in an exported sheet and is
                // not worth reporting as an error.
                if (array_filter($row, fn ($cell) => trim((string) $cell) !== '') !== []) {
                    $result['errors'][] = 'Row '.($lineNumber + 2).': no device_user_id.';
                }

                continue;
            }

            $existing = AttendanceDeviceUser::where('device_user_id', $deviceUserId)->first();

            if ($existing === null) {
                AttendanceDeviceUser::create([
                    'device_user_id' => $deviceUserId,
                    'device_name' => $deviceName !== '' ? $deviceName : null,
                ]);
                $result['new']++;

                continue;
            }

            if ($existing->isRetired()) {
                $result['skipped_retired']++;

                continue;
            }

            if ($deviceName !== '' && $existing->device_name !== $deviceName) {
                $existing->forceFill(['device_name' => $deviceName])->save();
                $result['renamed']++;

                continue;
            }

            $result['unchanged']++;
        }

        return $result;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function readRows(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'csv') {
            return $this->readCsv($path);
        }

        $sheet = IOFactory::load($path)->getActiveSheet();

        return array_values($sheet->toArray(null, true, true, false));
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function readCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'That file could not be opened.']);
        }

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        // A sheet saved from Excel starts with a UTF-8 BOM, which otherwise
        // becomes part of the first header name and makes the column
        // "﻿device_user_id" — invisible on screen, and a confusing failure.
        if (isset($rows[0][0]) && is_string($rows[0][0])) {
            $rows[0][0] = preg_replace('/^\x{FEFF}/u', '', $rows[0][0]);
        }

        return $rows;
    }

    /**
     * @param  array<int, mixed>  $header
     * @return array<int, string>
     */
    private function normaliseHeader(array $header): array
    {
        return array_map(
            fn ($cell) => str_replace([' ', '-'], '_', strtolower(trim((string) $cell))),
            $header,
        );
    }
}
