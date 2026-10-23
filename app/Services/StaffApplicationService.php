<?php

namespace App\Services;

use App\Models\StaffApplication;
use App\Models\StaffGuarantor;
use App\Models\User;
use App\Services\Attendance\AttendanceOnlyRoleService;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The whole life of a staff application: submit (public, from /join),
 * approve or reject (super admin). This is the only code that creates a
 * StaffApplication, and approve() is the only way one becomes a user.
 *
 * Nothing here ever chooses or sees a PIN, and nobody but the applicant
 * ever knows their password: they choose it on the form, only its bcrypt
 * hash is kept, and approval hands that hash to the new account.
 */
class StaffApplicationService
{
    public const APPROVE_STAFF_APPLICATIONS = 'approve_staff_applications';

    public function __construct(private StaffDocumentStore $documents = new StaffDocumentStore) {}

    /**
     * @param  array<string, mixed>  $data  already validated by the form
     * @param  array{photo: UploadedFile, id_document: UploadedFile, guarantor_form: UploadedFile}  $files
     *
     * @throws ValidationException
     */
    public function submit(array $data, array $files): StaffApplication
    {
        if (! StaffApplicationSettings::isOpen()) {
            throw ValidationException::withMessages(['form' => 'Applications are currently closed.']);
        }

        $email = Str::lower(trim((string) $data['email']));
        $this->assertEmailFree($email);

        $reference = $this->newReference();
        $directory = 'staff-applications/'.$reference;
        $stored = [];

        try {
            $stored['photo_path'] = $this->documents->storePhoto($files['photo'], $directory);
            $stored['id_document_path'] = $this->documents->storeDocument($files['id_document'], $directory, 'id');
            $stored['guarantor_form_path'] = $this->documents->storeDocument($files['guarantor_form'], $directory, 'guarantor-form');
        } catch (\Exception $e) {
            $this->documents->delete($stored);

            throw ValidationException::withMessages(['form' => $e->getMessage()]);
        }

        try {
            $application = StaffApplication::create([
                'reference' => $reference,
                'status' => StaffApplication::STATUS_PENDING,
                'full_name' => trim((string) $data['full_name']),
                'phone' => trim((string) $data['phone']),
                'email' => $email,
                'address' => trim((string) $data['address']),
                'password_hash' => Hash::make((string) $data['password']),
                'next_of_kin_name' => trim((string) $data['next_of_kin_name']),
                'next_of_kin_phone' => trim((string) $data['next_of_kin_phone']),
                'next_of_kin_relationship' => trim((string) $data['next_of_kin_relationship']),
                'bank_name' => $data['bank_name'],
                'account_number' => trim((string) $data['account_number']),
                'account_name' => trim((string) $data['account_name']),
                'guarantor_name' => trim((string) $data['guarantor_name']),
                'guarantor_phone' => trim((string) $data['guarantor_phone']),
                'guarantor_address' => trim((string) $data['guarantor_address']),
                'guarantor_relationship' => trim((string) $data['guarantor_relationship']),
                'guarantor_occupation' => trim((string) $data['guarantor_occupation']),
                'id_type' => $data['id_type'],
                'id_number' => trim((string) $data['id_number']),
                'submitted_at' => now(),
            ] + $stored);
        } catch (\Throwable $e) {
            $this->documents->delete($stored);

            throw $e;
        }

        // No name, phone or any other value in the log: the reference is
        // enough to find it.
        activity('staff_application')
            ->performedOn($application)
            ->log("Staff application {$reference} submitted");

        $this->notifySuperAdmins($application);

        return $application;
    }

    /**
     * Create the staff account from a pending application, in one transaction:
     * the user (with the applicant's own password hash and a new permanent
     * staff ID), their role, and their guarantor.
     *
     * @param  'app'|'attendance_only'  $staffType
     * @param  list<string>  $roles  role names; ignored for attendance-only
     *
     * @throws ValidationException
     */
    public function approve(StaffApplication $application, User $actor, string $staffType, array $roles = [], ?string $jobTitle = null): User
    {
        $this->assertMayDecide($actor);

        if ($staffType === 'app') {
            StaffAccessGuard::assertCanAssignRoles($actor);

            $roles = array_values(array_filter($roles, fn ($r) => $r !== User::ATTENDANCE_ONLY_ROLE));

            if ($roles === []) {
                throw ValidationException::withMessages(['roles' => 'Choose at least one role for an app user.']);
            }
        } elseif ($staffType !== 'attendance_only') {
            throw ValidationException::withMessages(['staff_type' => 'Choose App user or Attendance only.']);
        }

        return DB::transaction(function () use ($application, $actor, $staffType, $roles, $jobTitle) {
            $application = StaffApplication::lockForUpdate()->findOrFail($application->id);

            if (! $application->isPending()) {
                throw ValidationException::withMessages(['application' => 'This application has already been decided.']);
            }

            $this->assertEmailFree($application->email, ignoreApplicationId: $application->id);

            $user = new User;
            $user->forceFill([
                'name' => $application->full_name,
                'email' => $application->email,
                // Already a bcrypt hash; the 'hashed' cast stores it as is.
                'password' => $staffType === 'app' ? $application->password_hash : null,
                'staff_code' => StaffIdGenerator::next(),
                'job_title' => filled($jobTitle) ? trim($jobTitle) : null,
                'phone' => $application->phone,
                'address' => $application->address,
                'photo_path' => $application->photo_path,
                'next_of_kin_name' => $application->next_of_kin_name,
                'next_of_kin_phone' => $application->next_of_kin_phone,
                'next_of_kin_relationship' => $application->next_of_kin_relationship,
                'bank_name' => $application->bank_name,
                'account_number' => $application->account_number,
                'account_name' => $application->account_name,
                'id_type' => $application->id_type,
                'id_number' => $application->id_number,
                'id_card_copy' => $application->id_document_path,
                'guarantor_form' => $application->guarantor_form_path,
            ])->save();

            if ($staffType === 'attendance_only') {
                AttendanceOnlyRoleService::makeAttendanceOnly($user, $actor);
            } else {
                $user->syncRoles($roles);
            }

            StaffGuarantor::create([
                'user_id' => $user->id,
                'name' => $application->guarantor_name,
                'phone' => $application->guarantor_phone,
                'address' => $application->guarantor_address,
                'relationship' => $application->guarantor_relationship,
                'occupation' => $application->guarantor_occupation,
                'form_path' => $application->guarantor_form_path,
                'staff_application_id' => $application->id,
                'created_by' => $actor->id,
            ]);

            // The account now holds these; the application keeps no second
            // copy of the password hash or the encrypted numbers.
            $application->forceFill([
                'status' => StaffApplication::STATUS_APPROVED,
                'user_id' => $user->id,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'password_hash' => null,
                'account_number' => null,
                'id_number' => null,
            ])->save();

            activity('staff_application')
                ->performedOn($application)
                ->causedBy($actor)
                ->withProperties(['staff_code' => $user->staff_code, 'staff_type' => $staffType, 'roles' => $staffType === 'app' ? $roles : []])
                ->log("Staff application {$application->reference} approved");

            return $user->refresh();
        });
    }

    /**
     * Refuse an application and clear everything personal it held: the
     * fields and the uploaded files. The name, dates and reason stay as the
     * record that it was decided.
     *
     * @throws ValidationException
     */
    public function reject(StaffApplication $application, User $actor, string $reason): StaffApplication
    {
        $this->assertMayDecide($actor);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for rejecting.']);
        }

        $files = [];

        $application = DB::transaction(function () use ($application, $actor, $reason, &$files) {
            $application = StaffApplication::lockForUpdate()->findOrFail($application->id);

            if (! $application->isPending()) {
                throw ValidationException::withMessages(['application' => 'This application has already been decided.']);
            }

            $files = [$application->photo_path, $application->id_document_path, $application->guarantor_form_path];

            $application->forceFill(array_fill_keys(StaffApplication::PERSONAL_FIELDS, null) + [
                'status' => StaffApplication::STATUS_REJECTED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            activity('staff_application')
                ->performedOn($application)
                ->causedBy($actor)
                ->log("Staff application {$application->reference} rejected");

            return $application;
        });

        // After the commit: a rolled-back rejection must not lose the files.
        $this->documents->delete($files);

        return $application;
    }

    public static function canDecide(?User $actor): bool
    {
        return $actor !== null && $actor->can(self::APPROVE_STAFF_APPLICATIONS);
    }

    /**
     * @throws ValidationException
     */
    private function assertMayDecide(User $actor): void
    {
        if (! self::canDecide($actor)) {
            throw ValidationException::withMessages(['application' => 'Only the super admin can approve or reject applications.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertEmailFree(string $email, ?int $ignoreApplicationId = null): void
    {
        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'This email already belongs to a staff account. Use a different email, or ask the manager.']);
        }

        $pending = StaffApplication::pending()
            ->where('email', $email)
            ->when($ignoreApplicationId, fn ($q) => $q->whereKeyNot($ignoreApplicationId))
            ->exists();

        if ($pending) {
            throw ValidationException::withMessages(['email' => 'An application with this email is already waiting for review.']);
        }
    }

    private function newReference(): string
    {
        do {
            $reference = 'APP-'.Str::upper(Str::random(6));
        } while (StaffApplication::where('reference', $reference)->exists());

        return $reference;
    }

    private function notifySuperAdmins(StaffApplication $application): void
    {
        $superAdmins = User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->active()->get();

        if ($superAdmins->isEmpty()) {
            return;
        }

        Notification::make()
            ->info()
            ->title('New staff application')
            ->body("{$application->full_name} applied ({$application->reference}). Review it under Staff Applications.")
            ->sendToDatabase($superAdmins);
    }
}
