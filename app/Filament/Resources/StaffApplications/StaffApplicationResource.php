<?php

namespace App\Filament\Resources\StaffApplications;

use App\Filament\Resources\StaffApplications\Pages\ListStaffApplications;
use App\Filament\Resources\StaffApplications\Pages\ViewStaffApplication;
use App\Models\StaffApplication;
use App\Services\StaffDocumentStore;
use App\Support\NigerianBanks;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/**
 * Applications from the public /join form, for the super admin to approve
 * or reject. Read-only here: no create, edit or delete. Gated by
 * StaffApplicationPolicy (Shield, super_admin only); the decision actions
 * on the view page check approve_staff_applications again in the service.
 *
 * Photos and documents live on the private disk and are shown through
 * short-lived signed links only.
 */
class StaffApplicationResource extends Resource
{
    protected static ?string $model = StaffApplication::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-plus';

    protected static string|UnitEnum|null $navigationGroup = 'User Management';

    protected static ?string $navigationLabel = 'Staff Applications';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getNavigationBadge(): ?string
    {
        $pending = StaffApplication::pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable()->weight('bold'),
                TextColumn::make('full_name')->label('Name')->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => StaffApplication::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        StaffApplication::STATUS_PENDING => 'warning',
                        StaffApplication::STATUS_APPROVED => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('submitted_at')->label('Applied')->dateTime()->sortable(),
                TextColumn::make('user.staff_code')->label('Staff ID')->placeholder('—'),
                TextColumn::make('reviewed_at')->label('Decided')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(StaffApplication::STATUS_LABELS)
                    ->default(StaffApplication::STATUS_PENDING),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Application')->schema([
                TextEntry::make('reference'),
                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => StaffApplication::STATUS_LABELS[$state] ?? $state),
                TextEntry::make('submitted_at')->label('Applied')->dateTime(),
                TextEntry::make('reviewer.name')->label('Decided by')->placeholder('—'),
                TextEntry::make('reviewed_at')->label('Decided')->dateTime()->placeholder('—'),
                TextEntry::make('rejection_reason')->label('Reason')->placeholder('—')
                    ->visible(fn (StaffApplication $record) => $record->status === StaffApplication::STATUS_REJECTED),
                TextEntry::make('user.staff_code')->label('Staff ID')->placeholder('—')
                    ->visible(fn (StaffApplication $record) => $record->status === StaffApplication::STATUS_APPROVED),
            ])->columns(4),

            Section::make('About the applicant')->schema([
                ImageEntry::make('photo_path')
                    ->label('Photo')
                    ->disk(StaffDocumentStore::DISK)
                    ->visibility('private')
                    ->imageHeight(180)
                    ->placeholder('—'),
                TextEntry::make('full_name')->label('Full name'),
                TextEntry::make('phone')->placeholder('—'),
                TextEntry::make('email')->placeholder('—'),
                TextEntry::make('address')->placeholder('—')->columnSpan(2),
            ])->columns(3),

            Section::make('Next of kin')->schema([
                TextEntry::make('next_of_kin_name')->label('Name')->placeholder('—'),
                TextEntry::make('next_of_kin_phone')->label('Phone')->placeholder('—'),
                TextEntry::make('next_of_kin_relationship')->label('Relationship')->placeholder('—'),
            ])->columns(3),

            Section::make('Bank details')->schema([
                TextEntry::make('bank_name')->label('Bank')
                    ->formatStateUsing(fn (?string $state) => NigerianBanks::all()[$state] ?? $state)
                    ->placeholder('—'),
                TextEntry::make('account_number')->label('Account number')->placeholder('—'),
                TextEntry::make('account_name')->label('Account name')->placeholder('—'),
            ])->columns(3),

            Section::make('Guarantor')->schema([
                TextEntry::make('guarantor_name')->label('Name')->placeholder('—'),
                TextEntry::make('guarantor_phone')->label('Phone')->placeholder('—'),
                TextEntry::make('guarantor_relationship')->label('Relationship')->placeholder('—'),
                TextEntry::make('guarantor_occupation')->label('Occupation')->placeholder('—'),
                TextEntry::make('guarantor_address')->label('Address')->placeholder('—')->columnSpan(2),
                static::documentLink('guarantor_form_path', 'Signed guarantor form'),
            ])->columns(3),

            Section::make('Means of ID')->schema([
                TextEntry::make('id_type')->label('Type')
                    ->formatStateUsing(fn (?string $state) => StaffApplication::ID_TYPES[$state] ?? $state)
                    ->placeholder('—'),
                TextEntry::make('id_number')->label('Number')->placeholder('—'),
                static::documentLink('id_document_path', 'ID document'),
            ])->columns(3),
        ]);
    }

    /**
     * A private file (photo or PDF) opened through a 15-minute signed link.
     */
    protected static function documentLink(string $attribute, string $label): TextEntry
    {
        return TextEntry::make($attribute)
            ->label($label)
            ->formatStateUsing(fn (?string $state) => $state ? 'Open' : null)
            ->url(fn (StaffApplication $record) => $record->{$attribute}
                ? Storage::disk(StaffDocumentStore::DISK)->temporaryUrl($record->{$attribute}, now()->addMinutes(15))
                : null)
            ->openUrlInNewTab()
            ->color('primary')
            ->placeholder('—');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaffApplications::route('/'),
            'view' => ViewStaffApplication::route('/{record}'),
        ];
    }
}
