<?php

namespace App\Filament\Resources\Announcements\RelationManagers;

use App\Models\AnnouncementAcknowledgement;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read receipts: the frozen roster of who was required to read this
 * announcement, with each person's signature alongside.
 *
 * Entirely read-only, for every role including super_admin. A signature
 * that someone can add, edit or delete from an admin screen is not
 * evidence of anything — the only way a row here gains an acknowledged_at
 * is the person tapping the button themselves.
 */
class RecipientsRelationManager extends RelationManager
{
    protected static string $relationship = 'recipients';

    protected static ?string $title = 'Read receipts';

    public function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            // Signatures are pulled in as correlated subqueries rather
            // than an Eloquent relation: acknowledgements are keyed on the
            // (announcement, user) pair, which no single foreign key on
            // this table can express. One query, no N+1.
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('user')
                ->select('announcement_recipients.*')
                ->addSelect([
                    'acknowledged_at' => AnnouncementAcknowledgement::query()
                        ->select('acknowledged_at')
                        ->whereColumn('announcement_acknowledgements.user_id', 'announcement_recipients.user_id')
                        ->whereColumn('announcement_acknowledgements.announcement_id', 'announcement_recipients.announcement_id')
                        ->limit(1),
                    'ack_context' => AnnouncementAcknowledgement::query()
                        ->select('context')
                        ->whereColumn('announcement_acknowledgements.user_id', 'announcement_recipients.user_id')
                        ->whereColumn('announcement_acknowledgements.announcement_id', 'announcement_recipients.announcement_id')
                        ->limit(1),
                ])
            )
            // Ascending on a nullable column puts NULLs first in both
            // MySQL and SQLite, so the people who have not signed sit at
            // the top where a manager needs them.
            ->defaultSort('acknowledged_at', 'asc')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Staff member')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn ($record): string => $record->acknowledged_at ? 'Signed' : 'Outstanding')
                    ->color(fn ($record): string => $record->acknowledged_at ? 'success' : 'warning'),

                TextColumn::make('acknowledged_at')
                    ->label('Signed at')
                    ->dateTime('M j, Y g:ia')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('ack_context')
                    ->label('Signed from')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'kiosk' => 'Kiosk / phone',
                        'admin' => 'Admin panel',
                        default => '—',
                    })
                    ->placeholder('—'),

                TextColumn::make('is_late_join')
                    ->label('Added')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Joined after publishing' : 'On staff at publishing')
                    ->color(fn (bool $state): string => $state ? 'info' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('signed')
                    ->label('Status')
                    ->options([
                        'outstanding' => 'Not read yet',
                        'signed' => 'Read',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $exists = fn (Builder $q) => $q->whereExists(
                            fn ($sub) => $sub->selectRaw('1')
                                ->from('announcement_acknowledgements')
                                ->whereColumn('announcement_acknowledgements.user_id', 'announcement_recipients.user_id')
                                ->whereColumn('announcement_acknowledgements.announcement_id', 'announcement_recipients.announcement_id')
                        );

                        return match ($data['value'] ?? null) {
                            'signed' => $exists($query),
                            'outstanding' => $query->whereNotExists(
                                fn ($sub) => $sub->selectRaw('1')
                                    ->from('announcement_acknowledgements')
                                    ->whereColumn('announcement_acknowledgements.user_id', 'announcement_recipients.user_id')
                                    ->whereColumn('announcement_acknowledgements.announcement_id', 'announcement_recipients.announcement_id')
                            ),
                            default => $query,
                        };
                    }),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
