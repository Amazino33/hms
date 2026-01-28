<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['roles', 'warehouse']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
        ->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(255),

            TextInput::make('email')
                ->email()
                ->required()
                ->maxLength(255),

            // Password Field (Smart handling)
            TextInput::make('password')
                ->password()
                ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                ->dehydrated(fn ($state) => filled($state)) // Only save if user typed something
                ->required(fn (string $context): bool => $context === 'create'), // Required only on create

            Select::make('warehouse_id')
                ->label('Warehouse')
                ->relationship('warehouse', 'name')
                ->preload()
                ->searchable(),

            // 👇 THE MAGIC PART: Assign Roles here
            Select::make('roles')
                ->relationship('roles', 'name')
                ->multiple()
                ->preload()
                ->searchable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
        ->columns([
            TextColumn::make('name')->searchable(),
            TextColumn::make('email')->searchable(),
            
            TextColumn::make('warehouse.name')
                ->label('Warehouse')
                ->searchable()
                ->placeholder('—'),
            
            // Show their Role in the table
            TextColumn::make('roles.name')
                ->badge()
                ->color('info'),
                
            TextColumn::make('created_at')->dateTime(),
        ])
        ->paginated([10, 25, 50, 100])
        ->recordActions([
            Action::make('edit')
                ->label('Edit')
                ->icon(Heroicon::PencilSquare)
                ->url(fn (User $record): string => EditUser::getUrl(['record' => $record])),
            Action::make('delete')
                ->label('Delete')
                ->icon(Heroicon::Trash)
                ->color('danger')
                ->url(fn (User $record): string => EditUser::getUrl(['record' => $record])),
        ])
        ->toolbarActions([
            Action::make('create')
                ->label('Create User')
                ->icon(Heroicon::Plus)
                ->url(CreateUser::getUrl()),        
        ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
