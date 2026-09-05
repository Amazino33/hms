<?php

namespace App\Filament\Resources\Announcements\Schemas;

use App\Models\Announcement;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Role;

class AnnouncementForm
{
    /**
     * Once an announcement has been published, its wording, audience and
     * behaviour are frozen. "I have read this" only means something if the
     * text cannot be rewritten underneath the people who already signed
     * it — the same reasoning that makes FolioLine immutable.
     *
     * Withdrawing is always available, and the expiry stays editable,
     * because neither changes what anyone agreed to. To reword a live
     * notice, withdraw it and post a new one.
     */
    private static function locked(): \Closure
    {
        return fn (?Announcement $record): bool => $record?->published_at !== null;
    }

    public static function configure(Schema $schema): Schema
    {
        $locked = self::locked();

        return $schema
            ->components([
                Section::make('The notice')
                    ->description(fn (?Announcement $record) => $record?->published_at !== null
                        ? 'This announcement has been published, so its wording and audience are locked. Withdraw it and post a new one if it needs to change.'
                        : null
                    )
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->disabled($locked)
                            ->helperText('Shown in bold at the top of the note. Keep it short enough to read at a glance.'),

                        RichEditor::make('body')
                            ->required()
                            ->disabled($locked)
                            ->columnSpanFull(),

                        Radio::make('severity')
                            ->options([
                                'info' => 'Notice — routine information',
                                'warning' => 'Important — needs attention',
                                'critical' => 'Urgent — act on this now',
                            ])
                            ->default('info')
                            ->required()
                            ->disabled($locked)
                            ->helperText('Only changes the colour and the label. It does not decide whether the notice blocks the screen — that is the setting below.'),
                    ]),

                Section::make('Who sees it')
                    ->schema([
                        Radio::make('audience')
                            ->label('Send to')
                            ->options([
                                'all' => 'Everyone on staff',
                                'roles' => 'Only certain roles',
                            ])
                            ->default('all')
                            ->required()
                            ->disabled($locked)
                            ->live(),

                        CheckboxList::make('target_roles')
                            ->label('Roles')
                            ->options(fn () => Role::query()->orderBy('name')->pluck('name', 'name'))
                            ->columns(2)
                            ->disabled($locked)
                            // dehydrated() keeps the value in the submitted
                            // data even while disabled, so a locked record
                            // does not silently lose its targets on save.
                            ->dehydrated()
                            ->visible(fn ($get) => $get('audience') === 'roles')
                            ->required(fn ($get, ?Announcement $record) => $get('audience') === 'roles' && $record?->published_at === null)
                            ->helperText('The people in these roles are resolved into a fixed list the moment you publish. Someone who joins later still sees it, and is added to that list marked as a late join.'),

                        Toggle::make('show_on_kiosk')
                            ->label('Also show on kiosk / staff phone screens')
                            ->default(true)
                            ->disabled($locked)
                            ->helperText('Turn this off for office-only notices. Floor staff who work entirely from the PIN pad never open the admin panel, so leaving it on is usually what you want.'),
                    ]),

                Section::make('How hard it pushes')
                    ->schema([
                        Toggle::make('must_acknowledge')
                            ->label('Must be acknowledged before continuing')
                            ->default(true)
                            ->disabled($locked)
                            ->helperText('On: a modal covers the screen and the app cannot be used until the person taps "I have read this". Off: a corner note they can hide for now, which comes back at their next login until they sign it. Use "on" sparingly — a blocking notice will land on a waiter in the middle of service.'),

                        // Deliberately NOT locked after publishing: bringing
                        // a notice down early, or leaving it up longer,
                        // changes nothing about what anyone agreed to.
                        DateTimePicker::make('expires_at')
                            ->label('Show until')
                            ->seconds(false)
                            ->helperText('Leave blank to show it until you withdraw it. After this time it stops appearing for everyone, signed or not — the read receipts are kept either way.'),
                    ]),
            ]);
    }
}
