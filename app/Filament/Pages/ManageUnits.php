<?php

namespace App\Filament\Pages;

use App\Models\Unit;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * A simple managed reference list of unit names (bottle, crate, pack...)
 * so the Product form's unit selects offer a consistent set instead of
 * free text. Deliberately a lightweight settings page, not a full
 * Resource — this is just a name list, nothing else to manage per row.
 */
class ManageUnits extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';
    protected static string|UnitEnum|null $navigationGroup = 'Administration';
    protected static ?string $navigationLabel = 'Manage Units';
    protected static ?string $title = 'Manage Units';
    protected string $view = 'filament.pages.manage-units';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public array $data = [];

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->schema([
                TextInput::make('name')
                    ->label('New unit name')
                    ->required()
                    ->maxLength(50),
            ]);
    }

    public function units()
    {
        return Unit::orderBy('name')->get();
    }

    public function addUnit(): void
    {
        $data = $this->form->getState();
        $name = trim($data['name']);

        if (Unit::whereRaw('LOWER(name) = ?', [strtolower($name)])->exists()) {
            Notification::make()->title('That unit already exists')->warning()->send();

            return;
        }

        Unit::create(['name' => $name]);
        $this->form->fill();

        Notification::make()->title('Unit added')->success()->send();
    }

    public function deleteUnit(int $unitId): void
    {
        $unit = Unit::find($unitId);

        if (!$unit) {
            return;
        }

        // Products already carrying this name in purchase_unit_name/
        // base_unit keep it — those are plain strings, not a foreign key —
        // this only removes it from the picker for future selections.
        $unit->delete();

        Notification::make()->title('Unit removed')->success()->send();
    }
}
