<?php

namespace App\Filament\Pages;

use App\Models\TransferAccount;
use App\Models\User;
use App\Services\BrandingLogo;
use App\Services\Guest\GuestOrderingSettings;
use App\Services\MenuPhotoProcessor;
use App\Services\PermissionService;
use App\Services\UserFeedback;
use App\Support\NigerianPhone;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

/**
 * "Guest Ordering Settings" (Phase 1B): everything the guest QR menu needs
 * before it can go live — the transfer accounts shown on a bill, the
 * reception WhatsApp number for room orders, the porter list, and the
 * specials banner. Gated like every page, by PagePermission (seeded for
 * super_admin, admin and manager).
 */
class GuestOrdering extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static string|UnitEnum|null $navigationGroup = 'Guest Ordering';

    protected static ?string $navigationLabel = 'Guest Ordering Settings';

    protected static ?string $title = 'Guest Ordering Settings';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.guest-ordering';

    public array $data = [];

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public function mount(): void
    {
        $whatsapp = GuestOrderingSettings::receptionWhatsapp();
        $specials = GuestOrderingSettings::specials();

        $this->form->fill([
            'accounts' => TransferAccount::orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (TransferAccount $a) => $a->only(['id', 'bank_name', 'account_name', 'account_number', 'active']))
                ->all(),
            // Shown the way staff type it; stored internationally.
            'reception_whatsapp' => $whatsapp ? '0'.substr($whatsapp, 3) : null,
            'specials_text' => $specials['text'],
            'specials_image' => $specials['image_path'],
            'specials_active' => $specials['active'],
            'specials_starts_at' => $specials['starts_at']?->toDateTimeString(),
            'specials_ends_at' => $specials['ends_at']?->toDateTimeString(),
            'logo_mark' => BrandingLogo::hasMark() ? BrandingLogo::MARK : null,
            'quick_addons' => GuestOrderingSettings::quickAddons(),
            'round_delay_min' => GuestOrderingSettings::roundDelayMinutes(),
            'review_url' => GuestOrderingSettings::reviewUrl(),
            'specials_whatsapp_message' => GuestOrderingSettings::specialsWhatsappMessage(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Transfer accounts')
                ->description('Shown on a guest\'s bill, in this order, with a copy button for each number. Switch one off rather than removing it.')
                ->schema([
                    Repeater::make('accounts')
                        ->hiddenLabel()
                        ->schema([
                            Hidden::make('id'),
                            TextInput::make('bank_name')->label('Bank')->required()->maxLength(80),
                            TextInput::make('account_name')->label('Account name')->required()->maxLength(120),
                            TextInput::make('account_number')
                                ->label('Account number')
                                ->required()
                                ->inputMode('numeric')
                                ->regex('/^\d{10}$/')
                                ->validationMessages(['regex' => 'A Nigerian account number is exactly 10 digits.']),
                            Toggle::make('active')->default(true)->inline(false),
                        ])
                        ->columns(4)
                        ->reorderable()
                        ->deletable(false)
                        ->defaultItems(0)
                        ->addActionLabel('+ Add account'),
                ]),

            Section::make('Room orders')
                ->schema([
                    TextInput::make('reception_whatsapp')
                        ->label('Reception WhatsApp number')
                        ->tel()
                        ->placeholder('08012345678')
                        ->helperText('Room guests send their order here. Saved in international form (234…).')
                        ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                            if (filled($value) && ! NigerianPhone::toInternational($value)) {
                                $fail('That is not a Nigerian mobile number. Enter it like 08012345678.');
                            }
                        }),
                    Placeholder::make('porters')
                        ->label('Porters')
                        ->content(fn () => User::whereHas('roles', fn ($q) => $q->where('name', 'porter'))->whereNull('left_at')->orderBy('name')->pluck('name')->join(', ') ?: 'Nobody has the Porter role yet.')
                        ->helperText('Reception picks from staff who have the Porter role. Give or remove the role in Users.'),
                ])->columns(2),

            // Phase 7A: the guest page header's small logo. The full logo
            // (Company Settings) is used when there is none.
            Section::make('Guest page logo')
                ->description('The header of the guest menu uses this small mark. Without one, it uses the full company logo.')
                ->schema([
                    FileUpload::make('logo_mark')
                        ->label('Small logo mark')
                        ->helperText('Optional. For example the shield only, on a white or clear background. Shown at most 96 px wide.')
                        ->image()
                        ->disk(BrandingLogo::DISK)
                        ->visibility('public')
                        ->acceptedFileTypes(MenuPhotoProcessor::ACCEPTED_MIME_TYPES)
                        ->maxSize(MenuPhotoProcessor::MAX_KILOBYTES)
                        ->validationMessages(['mimetypes' => 'Upload a JPG, PNG or WebP image. '.MenuPhotoProcessor::HEIC_MESSAGE])
                        ->saveUploadedFileUsing(function (TemporaryUploadedFile $file, FileUpload $component): string {
                            try {
                                return BrandingLogo::publishMark($file);
                            } catch (\Throwable $e) {
                                throw ValidationException::withMessages([
                                    $component->getStatePath() => get_class($e) === \Exception::class ? $e->getMessage() : 'That image could not be read. Try a different one.',
                                ]);
                            }
                        })
                        ->deleteUploadedFileUsing(fn () => null),
                ]),
            // Phase 7C (D38): selling features — owner-set, honest only.
            Section::make('Selling')
                ->description('Suggestions shown to guests. Everything here is optional.')
                ->schema([
                    Select::make('quick_addons')
                        ->label('Quick add-ons')
                        ->helperText('Up to '.GuestOrderingSettings::QUICK_ADDONS_MAX.', in order. Offered in the cart as "Anything else?" — items already in the cart or sold out are skipped.')
                        ->multiple()
                        ->searchable()
                        ->reorderable()
                        ->maxItems(GuestOrderingSettings::QUICK_ADDONS_MAX)
                        ->options(fn () => \App\Support\GuestMenuOptions::itemOptions())
                        ->columnSpanFull(),
                    TextInput::make('round_delay_min')
                        ->label('"Another round?" delay (minutes)')
                        ->helperText('How long after drinks are ready to ask once. 0 turns it off.')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(GuestOrderingSettings::ROUND_DELAY_MAX)
                        ->required(),
                    TextInput::make('review_url')
                        ->label('Google review link')
                        ->helperText('Optional. Shown on the "Thanks for visiting" page. Must start with https://')
                        ->url()
                        ->startsWith(['https://'])
                        ->maxLength(500),
                    TextInput::make('specials_whatsapp_message')
                        ->label('WhatsApp specials message')
                        ->helperText('Pre-typed for guests who tap "Get our specials on WhatsApp". Sending it is their opt-in.')
                        ->maxLength(200)
                        ->columnSpanFull(),
                ])->columns(2),
            Section::make('Specials banner')
                ->description('An optional strip across the top of the guest menu.')
                ->schema([
                    Textarea::make('specials_text')
                        ->label('Text')
                        ->rows(2)
                        ->maxLength(GuestOrderingSettings::SPECIALS_TEXT_MAX)
                        ->live(debounce: 300)
                        ->hint(fn (?string $state) => mb_strlen((string) $state).' / '.GuestOrderingSettings::SPECIALS_TEXT_MAX),
                    FileUpload::make('specials_image')
                        ->label('Image (optional)')
                        ->image()
                        ->disk(MenuPhotoProcessor::DISK)
                        ->visibility('public')
                        ->acceptedFileTypes(MenuPhotoProcessor::ACCEPTED_MIME_TYPES)
                        ->maxSize(MenuPhotoProcessor::MAX_KILOBYTES)
                        ->validationMessages(['mimetypes' => 'Upload a JPG, PNG or WebP image. '.MenuPhotoProcessor::HEIC_MESSAGE])
                        ->saveUploadedFileUsing(function (TemporaryUploadedFile $file, FileUpload $component): string {
                            try {
                                return (new MenuPhotoProcessor)->process($file, withThumb: false, directory: 'specials')['photo_path'];
                            } catch (\Throwable $e) {
                                throw ValidationException::withMessages([
                                    $component->getStatePath() => get_class($e) === \Exception::class ? $e->getMessage() : 'That image could not be read. Try a different one.',
                                ]);
                            }
                        })
                        ->deleteUploadedFileUsing(fn () => null),
                    Toggle::make('specials_active')->label('Show the banner'),
                    DateTimePicker::make('specials_starts_at')->label('From (optional)')->seconds(false),
                    DateTimePicker::make('specials_ends_at')->label('Until (optional)')->seconds(false)->after('specials_starts_at'),
                ])->columns(2),
        ])->statePath('data');
    }

    public function save(): void
    {
        if (! static::canAccess()) {
            UserFeedback::blocked('Not allowed', 'Only a manager can change guest ordering settings.');

            return;
        }

        $data = $this->form->getState();

        try {
            DB::transaction(function () use ($data) {
                foreach (array_values($data['accounts'] ?? []) as $position => $row) {
                    $values = [
                        'bank_name' => trim($row['bank_name']),
                        'account_name' => trim($row['account_name']),
                        'account_number' => $row['account_number'],
                        'active' => (bool) ($row['active'] ?? true),
                        'sort_order' => $position + 1,
                    ];

                    filled($row['id'] ?? null)
                        ? TransferAccount::whereKey($row['id'])->firstOrFail()->update($values)
                        : TransferAccount::create($values);
                }

                GuestOrderingSettings::setReceptionWhatsapp($data['reception_whatsapp'] ?? null, auth()->user());

                GuestOrderingSettings::setQuickAddons(array_values((array) ($data['quick_addons'] ?? [])), auth()->user());
                GuestOrderingSettings::setRoundDelayMinutes($data['round_delay_min'] ?? GuestOrderingSettings::ROUND_DELAY_DEFAULT, auth()->user());
                GuestOrderingSettings::setReviewUrl($data['review_url'] ?? null, auth()->user());
                GuestOrderingSettings::setSpecialsWhatsappMessage($data['specials_whatsapp_message'] ?? null, auth()->user());

                // Cleared on the form: back to the full logo.
                if (blank($data['logo_mark'] ?? null)) {
                    BrandingLogo::removeMark();
                }

                GuestOrderingSettings::saveSpecials([
                    'text' => $data['specials_text'] ?? null,
                    'image_path' => $data['specials_image'] ?? null,
                    'active' => $data['specials_active'] ?? false,
                    'starts_at' => $data['specials_starts_at'] ?? null,
                    'ends_at' => $data['specials_ends_at'] ?? null,
                ], auth()->user());
            });
        } catch (\Exception $e) {
            if (get_class($e) !== \Exception::class) {
                throw $e;
            }

            UserFeedback::blocked('Settings not saved', $e->getMessage());

            return;
        }

        $this->mount();

        UserFeedback::succeeded('Guest ordering settings saved');
    }
}
