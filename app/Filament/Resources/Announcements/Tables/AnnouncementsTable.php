<?php

namespace App\Filament\Resources\Announcements\Tables;

use App\Models\Announcement;
use App\Services\AnnouncementService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AnnouncementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            // Both counts in the list query rather than per row — the
            // "signed / sent" column would otherwise fire two queries for
            // every announcement on the page.
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['recipients', 'acknowledgements']))
            ->columns([
                TextColumn::make('title')
                    ->weight('bold')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('severity')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'critical' => 'Urgent',
                        'warning' => 'Important',
                        default => 'Notice',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'critical' => 'danger',
                        'warning' => 'warning',
                        default => 'info',
                    }),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Announcement $record): string => $record->status())
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'scheduled' => 'info',
                        'draft' => 'gray',
                        'expired' => 'warning',
                        'unpublished' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('audience')
                    ->label('Sent to')
                    ->state(fn (Announcement $record): string => $record->audience === 'all'
                        ? 'Everyone'
                        : $record->targets->pluck('role_name')->implode(', ')
                    )
                    ->wrap(),

                TextColumn::make('read')
                    ->label('Signed')
                    ->state(fn (Announcement $record): string => $record->status() === 'draft'
                        ? '—'
                        : $record->acknowledgedCount().' / '.$record->recipientCount()
                    )
                    ->color(fn (Announcement $record): string => $record->outstandingCount() > 0 ? 'warning' : 'success')
                    ->description(fn (Announcement $record): ?string => $record->outstandingCount() > 0
                        ? $record->outstandingCount().' outstanding'
                        : null
                    ),

                TextColumn::make('must_acknowledge')
                    ->label('Blocking')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Blocks screen' : 'Dismissable')
                    ->color(fn (bool $state): string => $state ? 'danger' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('published_at')
                    ->label('Published')
                    ->dateTime('M j, Y g:ia')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('creator.name')
                    ->label('Posted by')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('severity')
                    ->options([
                        'info' => 'Notice',
                        'warning' => 'Important',
                        'critical' => 'Urgent',
                    ]),

                SelectFilter::make('state')
                    ->label('Status')
                    ->options([
                        'live' => 'Currently showing',
                        'draft' => 'Draft',
                        'finished' => 'Expired or withdrawn',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'live' => $query->live(),
                            'draft' => $query->whereNull('published_at'),
                            'finished' => $query->whereNotNull('published_at')->where(
                                fn (Builder $q) => $q->whereNotNull('unpublished_at')
                                    ->orWhere('expires_at', '<=', now())
                            ),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                Action::make('publish')
                    ->label(fn (Announcement $record) => $record->status() === 'unpublished' ? 'Re-publish' : 'Publish')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->visible(fn (Announcement $record) => in_array($record->status(), ['draft', 'unpublished'], true))
                    ->schema([
                        DateTimePicker::make('published_at')
                            ->label('Publish at')
                            ->seconds(false)
                            ->helperText('Leave blank to publish immediately.'),
                    ])
                    ->modalHeading('Publish this announcement')
                    ->modalDescription('The list of people required to read it is fixed at this moment and kept for the record. Anyone who joins later still sees it while it is showing.')
                    ->modalSubmitActionLabel('Publish')
                    ->action(function (Announcement $record, array $data): void {
                        // A role-targeted notice with no roles selected
                        // would publish to nobody and look like it worked.
                        // Surfaced as a notification rather than an abort,
                        // per the no-bare-abort rule.
                        if ($record->audience === 'roles' && $record->targets()->count() === 0) {
                            Notification::make()
                                ->title('No roles selected')
                                ->body('This announcement is set to go to specific roles but none are chosen, so nobody would receive it. Edit it and pick at least one role.')
                                ->danger()
                                ->persistent()
                                ->send();

                            return;
                        }

                        $publishAt = filled($data['published_at'] ?? null)
                            ? \Illuminate\Support\Carbon::parse($data['published_at'])
                            : null;

                        $published = app(AnnouncementService::class)->publish($record, $publishAt);

                        $count = $published->recipients()->count();

                        Notification::make()
                            ->title($published->status() === 'scheduled' ? 'Scheduled' : 'Published')
                            ->body(trans_choice(
                                '{0}Nobody currently matches this audience.|{1}:count person is required to read it.|[2,*]:count people are required to read it.',
                                $count,
                                ['count' => $count],
                            ))
                            ->success()
                            ->send();
                    }),

                Action::make('unpublish')
                    ->label('Withdraw')
                    ->icon('heroicon-o-eye-slash')
                    ->color('danger')
                    ->visible(fn (Announcement $record) => in_array($record->status(), ['published', 'scheduled'], true))
                    ->requiresConfirmation()
                    ->modalHeading('Withdraw this announcement')
                    ->modalDescription('It stops appearing on every screen straight away. Who was asked, and who has already signed, is kept.')
                    ->modalSubmitActionLabel('Withdraw')
                    ->action(function (Announcement $record): void {
                        app(AnnouncementService::class)->unpublish($record);

                        Notification::make()
                            ->title('Withdrawn')
                            ->body('It is no longer showing. The read receipts are still on the record.')
                            ->success()
                            ->send();
                    }),

                EditAction::make(),
            ]);
    }
}
