<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use UnitEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|UnitEnum|null $navigationGroup = 'User Management';

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
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),

                // Password Field (Smart handling)
                TextInput::make('password')
                    ->password()
                    ->dehydrateStateUsing(fn($state) => Hash::make($state))
                    ->dehydrated(fn($state) => filled($state)) // Only save if user typed something
                    ->required(fn(string $context): bool => $context === 'create'), // Required only on create

                Tabs::make('Staff Details')
                    ->tabs([
                        // TAB 1: Work Info
                        Tab::make('Work Profile')
                            ->icon('heroicon-m-briefcase')
                            ->schema([
                                Grid::make(3)->schema([
                                    TextInput::make('staff_code')
                                        ->placeholder('e.g. LH-001')
                                        ->unique(ignoreRecord: true),
                                    Select::make('roles')
                                        ->relationship('roles', 'name')
                                        ->multiple()
                                        ->preload(),
                                    Select::make('primary_location')
                                        ->options([
                                            'main_bar' => 'Main Bar',
                                            'restaurant' => 'Restaurant',
                                            'kitchen' => 'Kitchen',
                                        ]),
                                ]),
                            ]),

                        // TAB 2: Financials
                        Tab::make('Payroll & Bank')
                            ->icon('heroicon-m-banknotes')
                            ->schema([
                                Grid::make(2)->schema([
                                    TextInput::make('base_salary')
                                        ->numeric()
                                        ->prefix('₦'),
                                    TextInput::make('total_commission')
                                        ->label('Total Commission Earned')
                                        ->prefix('₦')
                                        ->readOnly()
                                        ->visible(fn ($record) => $record && $record->hasRole(['waiter']))
                                        ->afterStateHydrated(function (TextInput $component, $record) {
                                            if ($record) {
                                                // Fetch the sum directly from the commissions relationship
                                                $sum = $record->commissions()->sum('amount');
                                                $component->state(number_format($sum, 2));
                                            } else {
                                                $component->state('0.00');
                                            }
                                        }),
                                    Select::make('bank_name')
                                        ->label('Bank Name')
                                        ->options(self::getNigerianBanks())
                                        ->searchable()
                                        ->preload() // Helps with performance on the client side
                                        ->placeholder('Select a bank'),
                                    TextInput::make('account_number')
                                        ->label('Account Number')
                                        ->numeric()
                                        ->minLength(10)
                                        ->maxLength(10)
                                        ->requiredWith('bank_name'),
                                    TextInput::make('account_name')
                                        ->label('Account Name')
                                        ->maxLength(255)
                                        ->minLength(3)
                                        ->requiredWith('bank_name'),
                                ]),
                            ]),

                        // TAB 3: Documents & KYC
                        Tab::make('Identity & KYC')
                            ->icon('heroicon-m-identification')
                            ->schema([
                                Grid::make(2)->schema([
                                    Select::make('id_type')
                                        ->options(['nin' => 'NIN', 'voters' => 'Voters Card']),
                                    TextInput::make('id_number'),
                                    FileUpload::make('id_card_copy')
                                        ->directory('staff-docs'),
                                    FileUpload::make('guarantor_form')
                                        ->directory('guarantors'),
                                ]),
                            ]),

                        // TAB 4: Emergency
                        Tab::make('Emergency')
                            ->icon('heroicon-m-phone')
                            ->schema([
                                TextInput::make('next_of_kin_name'),
                                TextInput::make('next_of_kin_phone')->tel(),
                            ]),

                        Tab::make('Commissions')
                            ->icon('heroicon-m-presentation-chart-line')
                            ->schema([
                                Placeholder::make('total_earned')
                                    ->label('Lifetime Earnings')
                                    ->content(fn($record) => $record ? '₦' . number_format($record->commissions()->sum('amount'), 2) : '₦0.00'),

                                Section::make('Commission History')
                                    ->description('Recent earnings from served orders')
                                    ->schema([
                                        Repeater::make('commissions')
                                            ->relationship() // Fetches from the commissions() relationship
                                            ->schema([
                                                Grid::make(3)->schema([
                                                    TextInput::make('order_number')
                                                        ->label('Order #')
                                                        ->disabled(),
                                                    TextInput::make('amount')
                                                        ->label('Earned')
                                                        ->prefix('₦')
                                                        ->disabled(),
                                                    TextInput::make('created_at')
                                                        ->label('Date')
                                                        ->disabled(),
                                                ]),
                                            ])
                                            ->addable(false) // Commissions should be system-generated, not manual
                                            ->deletable(false)
                                            ->reorderable(false)
                                            ->columnSpanFull(),
                                    ])->collapsible(),
                            ]),
                    ])
                    ->columnSpanFull(),
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
                    ->url(fn(User $record): string => EditUser::getUrl(['record' => $record])),
                Action::make('forceResetPin')
                    ->label('Force Reset PIN')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Clears their kiosk PIN entirely — you never see or choose it, they must set a brand new one next time.')
                    ->action(function (User $record) {
                        try {
                            (new \App\Services\PinAuthService())->forceReset($record, auth()->user());
                            \App\Services\UserFeedback::succeeded('PIN reset', "{$record->name} must set a new PIN next time.");
                        } catch (\Throwable $e) {
                            report($e);
                            \App\Services\UserFeedback::failed('Could not reset PIN');
                        }
                    }),
                Action::make('delete')
                    ->label('Delete')
                    ->icon(Heroicon::Trash)
                    ->color('danger')
                    ->url(fn(User $record): string => EditUser::getUrl(['record' => $record])),
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

    protected static function getNigerianBanks(): array
    {
        return [
            'access' => 'Access Bank',
            'fidelity' => 'Fidelity Bank',
            'fcmb' => 'First City Monument Bank (FCMB)',
            'first_bank' => 'First Bank of Nigeria',
            'gtbank' => 'Guaranty Trust Bank (GTB)',
            'heritage' => 'Heritage Bank',
            'keystone' => 'Keystone Bank',
            'moniepoint' => 'Moniepoint MFB',
            'opay' => 'OPay (Digital Bank)',
            'palmpay' => 'PalmPay',
            'polaris' => 'Polaris Bank',
            'providus' => 'Providus Bank',
            'stanbic' => 'Stanbic IBTC Bank',
            'standard_chartered' => 'Standard Chartered',
            'sterling' => 'Sterling Bank',
            'suntrust' => 'SunTrust Bank',
            'union' => 'Union Bank',
            'uba' => 'United Bank for Africa (UBA)',
            'unity' => 'Unity Bank',
            'wema' => 'Wema Bank',
            'zenith' => 'Zenith Bank',
        ];
    }
}
