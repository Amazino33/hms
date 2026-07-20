<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use UnitEnum;

class ManageCompanySettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office';
    protected static string|UnitEnum|null $navigationGroup = 'System Management';
    protected static ?string $title = 'Company Settings';
    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.manage-company-settings';

    // Form field state
    public array $data = [];

    /** The single Company record we are editing. */
    protected Company $company;

    public function mount(): void
    {
        // Always ensure a record exists so the form has a target
        $this->company = Company::firstOrCreate(
            ['id' => 1],
            ['name' => config('app.name', 'My Company')]
        );

        $this->form->fill([
            'name'                  => $this->company->name,
            'address'               => $this->company->address,
            'phone_number'          => $this->company->phone_number,
            'logo_path'             => $this->company->logo_path,
            'handover_count_scope'  => $this->company->handover_count_scope,
            'maintenance_message'   => $this->company->maintenance_message,
            'maintenance_duration_minutes' => $this->company->maintenance_duration_minutes ?? 15,
            'maintenance_secret'    => $this->company->maintenance_secret,
        ]);
    }

    public function isMaintenanceActive(): bool
    {
        return app()->maintenanceMode()->active();
    }

    /**
     * Deliberately re-queries rather than reading $this->company — that
     * property is only ever set inside mount(), and as a non-public
     * property it is NOT rehydrated by Livewire between separate requests
     * (e.g. the initial render vs. a later action call), so relying on it
     * anywhere outside mount() throws "must not be accessed before
     * initialization" on any request that isn't the very first one.
     */
    public function bypassUrl(): ?string
    {
        $secret = Company::find(1)?->maintenance_secret;

        if (empty($secret)) {
            return null;
        }

        return rtrim(config('app.url'), '/') . '/' . $secret;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->schema([
                TextInput::make('name')
                    ->label('Company Name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('phone_number')
                    ->label('Phone Number')
                    ->tel()
                    ->maxLength(50),

                Textarea::make('address')
                    ->label('Address')
                    ->rows(3)
                    ->columnSpanFull(),

                FileUpload::make('logo_path')
                    ->label('Company Logo')
                    ->image()
                    ->directory('company-logos')
                    ->imagePreviewHeight('80')
                    ->columnSpanFull(),

                Select::make('handover_count_scope')
                    ->label('Bar/Kitchen Handover Count Scope')
                    ->helperText('Applies to the next handover count session that gets opened — never changes a session already in progress.')
                    ->options([
                        'all' => 'Count every bar-stocked product (recommended while testing)',
                        'in_stock_only' => 'Count only products currently showing stock',
                    ])
                    ->default('all')
                    ->required()
                    ->native(false)
                    ->columnSpanFull(),

                Section::make('Maintenance Mode')
                    ->description('What visitors see while the site is down for a deploy. Saving these fields alone does NOT turn maintenance mode on — use the buttons above to actually switch it on/off.')
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('maintenance_message')
                            ->label('Message shown to visitors')
                            ->rows(3)
                            ->placeholder("We're making some improvements. Hang tight, we'll be back shortly.")
                            ->columnSpanFull(),

                        TextInput::make('maintenance_duration_minutes')
                            ->label('Expected duration (minutes)')
                            ->helperText('Only used to show visitors an estimated countdown — it does not automatically turn maintenance mode back off.')
                            ->numeric()
                            ->minValue(1)
                            ->default(15)
                            ->required(),

                        TextInput::make('maintenance_secret')
                            ->label('Bypass secret')
                            ->helperText('Visiting yourdomain.com/<this> once lets that browser see the real site while everyone else sees the maintenance page. Changing this only takes effect the next time maintenance mode is turned on.')
                            ->maxLength(64),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        Company::updateOrCreate(
            ['id' => 1],
            $data
        );

        Notification::make()
            ->title('Company settings saved successfully.')
            ->success()
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save Settings')
                ->submit('save'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('enableMaintenance')
                ->label('Enable Maintenance Mode')
                ->color('danger')
                ->icon('heroicon-o-exclamation-triangle')
                ->visible(fn () => ! $this->isMaintenanceActive())
                ->requiresConfirmation()
                ->modalDescription('Every visitor without the bypass secret will see the maintenance page instead of the real site until you disable this. Save any message/duration changes first — this uses whatever is currently saved.')
                ->action(function () {
                    $company = Company::firstOrCreate(['id' => 1], ['name' => config('app.name', 'My Company')]);

                    if (empty($company->maintenance_secret)) {
                        $company->maintenance_secret = Str::random(32);
                        $company->save();
                    }

                    Artisan::call('hms:maintenance-down');

                    // Without this, the admin who just clicked this button
                    // would be bounced to the maintenance page themselves on
                    // their very next request — they haven't visited the
                    // bypass URL, so their browser has no bypass cookie yet.
                    Cookie::queue(MaintenanceModeBypassCookie::create($company->maintenance_secret));

                    Notification::make()
                        ->title('Maintenance mode enabled')
                        ->body('Bypass URL: ' . $this->bypassUrl())
                        ->warning()
                        ->persistent()
                        ->send();
                }),

            Action::make('disableMaintenance')
                ->label('Disable Maintenance Mode')
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->visible(fn () => $this->isMaintenanceActive())
                ->requiresConfirmation()
                ->action(function () {
                    Artisan::call('hms:maintenance-up');

                    Notification::make()
                        ->title('Maintenance mode disabled — the site is live again')
                        ->success()
                        ->send();
                }),
        ];
    }

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }
}
