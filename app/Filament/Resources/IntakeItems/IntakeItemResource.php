<?php

namespace App\Filament\Resources\IntakeItems;

use App\Enums\AttachmentType;
use App\Enums\IntakeStatus;
use App\Filament\Resources\IntakeItems\Pages\ListIntakeItems;
use App\Filament\Resources\IntakeItems\Pages\TriageIntakeItem;
use App\Intake\RuleMatcher;
use App\Intake\TaskName;
use App\Intake\TriageActions;
use App\Intake\TriageResult;
use App\Models\IntakeItem;
use App\Models\User;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * "Eingang": the triage queue (step 4). Members see their own items only, admins all (R12),
 * both in the query and in the policy. Items are never created or deleted here (E7).
 */
class IntakeItemResource extends Resource
{
    protected static ?string $model = IntakeItem::class;

    protected static ?string $slug = 'eingang';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?string $navigationLabel = 'Eingang';

    protected static ?string $modelLabel = 'Mail';

    protected static ?string $pluralModelLabel = 'Eingang';

    protected static ?int $navigationSort = 1;

    protected static bool $isGloballySearchable = false;

    /**
     * Columns for the table. The bodies can be large and are not needed there.
     */
    private const LIST_COLUMNS = [
        'id', 'user_id', 'subject', 'from_name', 'from_email', 'received_at', 'status', 'error',
        'imported_at',
    ];

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        $query = parent::getEloquentQuery()->visibleInTriage();

        if (! $user instanceof User || ! $user->isAdmin()) {
            $query->where('user_id', $user instanceof User ? $user->id : null);
        }

        return $query;
    }

    public static function getRecordTitle(?Model $record): string
    {
        return $record instanceof IntakeItem ? TaskName::fromSubject($record->subject) : static::getModelLabel();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->select(array_map(fn (string $column): string => 'intake_items.'.$column, self::LIST_COLUMNS))
                ->with('user:id,name,last_clickup_list_label')
                ->withCount(['attachments as listed_attachments_count' => fn (Builder $query): Builder => $query
                    ->where('is_inline', false)
                    ->where('type', '!=', AttachmentType::Reference->value)]))
            ->defaultSort('received_at', 'desc')
            ->columns([
                TextColumn::make('received_at')
                    ->label('Empfangen')
                    ->dateTime('d.m.Y H:i', config('intake.display_timezone'))
                    ->sortable(),
                TextColumn::make('from_name')
                    ->label('Von')
                    ->state(fn (IntakeItem $record): ?string => $record->from_name ?: $record->from_email)
                    ->description(fn (IntakeItem $record): ?string => $record->from_name ? $record->from_email : null)
                    ->searchable(['from_name', 'from_email']),
                TextColumn::make('subject')
                    ->label('Betreff')
                    ->state(fn (IntakeItem $record): string => $record->subject ?: TaskName::EMPTY_SUBJECT)
                    ->limit(80)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('listed_attachments_count')
                    ->label('Anhänge')
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (IntakeStatus $state): string => $state->label())
                    ->color(fn (IntakeStatus $state): string => self::statusColor($state))
                    ->tooltip(fn (IntakeItem $record): ?string => $record->status === IntakeStatus::Failed ? $record->error : null),
                TextColumn::make('preselected_list')
                    ->label('Vorbelegte List')
                    ->state(fn (IntakeItem $record): ?string => self::preselectedListLabel($record))
                    ->placeholder('Keine')
                    ->toggleable(),
                TextColumn::make('user.name')
                    ->label('Benutzer')
                    ->visible(fn (): bool => self::isAdmin()),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(IntakeStatus::cases())->mapWithKeys(
                        fn (IntakeStatus $status): array => [$status->value => $status->label()],
                    )->all())
                    ->default(IntakeStatus::Pending->value),
                SelectFilter::make('user_id')
                    ->label('Benutzer')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->visible(fn (): bool => self::isAdmin()),
            ])
            ->recordActions([])
            ->toolbarActions([
                BulkAction::make('discard')
                    ->label('Verwerfen')
                    ->icon(Heroicon::OutlinedArchiveBoxXMark)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Ausgewählte Mails verwerfen')
                    ->modalDescription('In Outlook wird die Kategorie «ClickUp» entfernt. Bereits bearbeitete Mails werden übersprungen.')
                    ->modalSubmitActionLabel('Verwerfen')
                    ->authorizeIndividualRecords('process')
                    ->action(function (Collection $records): void {
                        self::discardAll($records);
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->emptyStateHeading('Keine Mails')
            ->emptyStateDescription('Markiere eine Mail in Outlook mit der Kategorie «ClickUp». Sie erscheint hier nach wenigen Minuten.');
    }

    public static function statusColor(IntakeStatus $status): string
    {
        return match ($status) {
            IntakeStatus::Pending => 'info',
            IntakeStatus::Creating => 'warning',
            IntakeStatus::Created, IntakeStatus::Forwarded => 'success',
            IntakeStatus::Discarded => 'gray',
            IntakeStatus::Failed => 'danger',
        };
    }

    /**
     * List label of the first matching rule, otherwise the last list of the mailbox owner.
     * The ClickUp select of step 5 uses the same order.
     */
    public static function preselectedListLabel(IntakeItem $item): ?string
    {
        return app(RuleMatcher::class)->match($item)?->clickup_list_label
            ?? $item->user?->last_clickup_list_label;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIntakeItems::route('/'),
            'view' => TriageIntakeItem::route('/{record}'),
        ];
    }

    /**
     * @param  Collection<int, IntakeItem>  $records  already filtered by the policy "process"
     */
    private static function discardAll(Collection $records): void
    {
        $actor = Auth::user();
        assert($actor instanceof User);
        $actions = app(TriageActions::class);

        $results = $records->map(fn (IntakeItem $item): TriageResult => $actions->discard($item, $actor));
        $discarded = $results->filter(fn (TriageResult $result): bool => in_array($result, [TriageResult::Done, TriageResult::CategoryPending], true))->count();
        $pending = $results->filter(fn (TriageResult $result): bool => $result === TriageResult::CategoryPending)->count();

        $notification = Notification::make()
            ->title($discarded === 1 ? '1 Mail verworfen.' : "{$discarded} Mails verworfen.")
            ->success();

        if ($pending > 0) {
            $notification->body('Die Kategorie in Outlook wird beim nächsten Abgleich angepasst.')->warning();
        }

        $notification->send();
    }

    private static function isAdmin(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isAdmin();
    }
}
