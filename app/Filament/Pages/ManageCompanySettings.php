<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
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
            'name'         => $this->company->name,
            'address'      => $this->company->address,
            'phone_number' => $this->company->phone_number,
            'logo_path'    => $this->company->logo_path,
        ]);
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

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }
}
