<?php

namespace App\Filament\Resources\IntakeItems\Pages;

use App\Enums\AttachmentType;
use App\Enums\IntakeStatus;
use App\Filament\Resources\IntakeItems\IntakeItemResource;
use App\Intake\MailMarkdown;
use App\Intake\SafeLinkConverter;
use App\Intake\TaskName;
use App\Intake\TriageActions;
use App\Intake\TriageResult;
use App\Models\IntakeAttachment;
use App\Models\IntakeItem;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;

/**
 * Triage of one mail (step 4): mail header, task fields, original in a sandboxed iframe
 * (R13) and the actions. The task fields live in $data only and are not saved here: step 5
 * adds list, assignees, due date, priority, the comment option and "Task erstellen". That
 * action only reads $this->form->getState() and passes it to
 * TriageActions::createTask($item, $actor, $draft), which stores the attachment selection.
 * The page holds no business logic.
 *
 * Mail content is shown escaped only, never with {!! !!}.
 *
 * @extends ViewRecord<IntakeItem>
 */
class TriageIntakeItem extends ViewRecord
{
    protected static string $resource = IntakeItemResource::class;

    protected static ?string $breadcrumb = 'Triage';

    /** @var Collection<int, IntakeAttachment>|null */
    private ?Collection $listedAttachments = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->fillTriageForm();
    }

    public function getTitle(): string|Htmlable
    {
        return TaskName::fromSubject($this->getRecord()->subject);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->model($this->getRecord())
            ->operation('edit')
            ->statePath('data')
            ->disabled(fn (): bool => ! $this->canProcess());
    }

    public function form(Schema $schema): Schema
    {
        $attachments = $this->listedAttachments();

        return $schema->components([
            TextInput::make('task_name')
                ->label('Taskname')
                ->required()
                ->maxLength(500),
            MarkdownEditor::make('description')
                ->label('Beschreibung')
                ->fileAttachments(false)
                ->maxLength(MailMarkdown::MAX_LENGTH)
                ->toolbarButtons([
                    ['bold', 'italic', 'strike', 'link'],
                    ['heading', 'blockquote', 'bulletList', 'orderedList'],
                    ['undo', 'redo'],
                ])
                ->maxHeight('32rem'),
            CheckboxList::make('attachment_ids')
                ->label('Anhänge für ClickUp')
                ->options($attachments->mapWithKeys(fn (IntakeAttachment $attachment): array => [
                    (string) $attachment->id => $attachment->name,
                ])->all())
                ->descriptions($attachments->mapWithKeys(fn (IntakeAttachment $attachment): array => [
                    (string) $attachment->id => self::attachmentDescription($attachment),
                ])->all())
                ->disableOptionWhen(fn (string $value): bool => ! ($attachments->firstWhere('id', (int) $value)?->isUploadable() ?? false))
                ->bulkToggleable()
                ->visible($attachments->isNotEmpty()),
        ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'sm' => 2])
            ->components([
                TextEntry::make('from')
                    ->label('Von')
                    ->state(fn (IntakeItem $record): string => self::address($record->from_name, $record->from_email)),
                TextEntry::make('received_at')
                    ->label('Empfangen')
                    ->dateTime('d.m.Y H:i', config('intake.display_timezone')),
                TextEntry::make('to')
                    ->label('An')
                    ->state(fn (IntakeItem $record): array => self::addresses($record->to_json))
                    ->listWithLineBreaks()
                    ->limitList(5)
                    ->expandableLimitedList()
                    ->placeholder('Keine'),
                TextEntry::make('cc')
                    ->label('CC')
                    ->state(fn (IntakeItem $record): array => self::addresses($record->cc_json))
                    ->listWithLineBreaks()
                    ->limitList(5)
                    ->expandableLimitedList()
                    ->placeholder('Keine'),
                TextEntry::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (IntakeStatus $state): string => $state->label())
                    ->color(fn (IntakeStatus $state): string => IntakeItemResource::statusColor($state)),
                TextEntry::make('mailbox_upn')
                    ->label('Postfach')
                    ->visible(fn (): bool => $this->currentUser()->isAdmin()),
                TextEntry::make('processed')
                    ->label('Bearbeitet')
                    ->state(fn (IntakeItem $record): ?string => $record->processed_at === null ? null : trim(
                        $record->processed_at->timezone(config('intake.display_timezone'))->format('d.m.Y H:i')
                        .' '.($record->loadMissing('processedBy')->processedBy?->name ?? ''),
                    ))
                    ->visible(fn (IntakeItem $record): bool => $record->status->isFinal()),
                TextEntry::make('error')
                    ->label('Fehler')
                    ->color('danger')
                    ->columnSpanFull()
                    ->visible(fn (IntakeItem $record): bool => $record->status === IntakeStatus::Failed && filled($record->error)),
                View::make('filament.intake.attachment-links')
                    ->viewData(['attachments' => $this->listedAttachments()->filter(
                        fn (IntakeAttachment $attachment): bool => $attachment->storage_path !== null,
                    )])
                    ->columnSpanFull(),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'xl' => 2])->schema([
                Group::make([
                    Section::make('Mail')->schema([EmbeddedSchema::make('infolist')]),
                    Section::make('Task')->schema([EmbeddedSchema::make('form')]),
                ]),
                Section::make('Original')
                    ->schema([
                        View::make('filament.intake.original-mail')
                            ->viewData(['src' => route('intake.original', ['item' => $this->getRecord()->id])]),
                    ]),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $address = TriageActions::supportAddress();

        return [
            Action::make('forwardToSupport')
                ->label('An Support weiterleiten')
                ->icon(Heroicon::OutlinedLifebuoy)
                ->color('gray')
                ->authorize('forward')
                ->visible($address !== null)
                ->requiresConfirmation()
                ->modalHeading('An Support weiterleiten')
                ->modalDescription("Die Mail wird aus dem Postfach {$this->getRecord()->mailbox_upn} an {$address} weitergeleitet.")
                ->modalSubmitActionLabel('Weiterleiten')
                ->action(fn () => $this->afterAction(
                    app(TriageActions::class)->forwardToSupport($this->getRecord(), $this->currentUser()),
                    done: 'An den Support weitergeleitet.',
                    categoryPending: 'An den Support weitergeleitet. Die Kategorie in Outlook wird beim nächsten Abgleich gesetzt.',
                )),
            Action::make('discard')
                ->label('Verwerfen')
                ->icon(Heroicon::OutlinedArchiveBoxXMark)
                ->color('gray')
                ->authorize('process')
                ->requiresConfirmation()
                ->modalHeading('Mail verwerfen')
                ->modalDescription('In Outlook wird die Kategorie «ClickUp» entfernt. Wird die Mail später wieder markiert, erscheint sie erneut im Eingang.')
                ->modalSubmitActionLabel('Verwerfen')
                ->action(fn () => $this->afterAction(
                    app(TriageActions::class)->discard($this->getRecord(), $this->currentUser()),
                    done: 'Mail verworfen.',
                    categoryPending: 'Mail verworfen. Die Kategorie in Outlook wird beim nächsten Abgleich angepasst.',
                )),
            Action::make('openInOutlook')
                ->label('In Outlook öffnen')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->authorize('view')
                ->visible(fn (): bool => $this->outlookUrl() !== null)
                ->url(fn (): ?string => $this->outlookUrl(), shouldOpenInNewTab: true)
                ->extraAttributes(['rel' => 'noopener noreferrer']),
        ];
    }

    private function afterAction(TriageResult $result, string $done, string $categoryPending): void
    {
        match ($result) {
            TriageResult::Done => Notification::make()->title($done)->success()->send(),
            TriageResult::CategoryPending => Notification::make()->title($categoryPending)->warning()->send(),
            TriageResult::OwnerInactive => Notification::make()->title($done)
                ->body('Die Kategorie in Outlook kann nicht mehr angepasst werden, weil der Benutzer deaktiviert ist.')
                ->warning()->send(),
            TriageResult::Failed => Notification::make()->title('Fehlgeschlagen')->body($this->getRecord()->error)->danger()->persistent()->send(),
            TriageResult::Skipped => Notification::make()->title('Die Mail wurde inzwischen bearbeitet.')->warning()->send(),
        };

        if ($result !== TriageResult::Failed) {
            $this->redirect(IntakeItemResource::getUrl('index'));
        }
    }

    private function fillTriageForm(): void
    {
        $item = $this->getRecord();

        $this->form->fill([
            'task_name' => TaskName::fromSubject($item->subject),
            'description' => app(MailMarkdown::class)->convert($item->body_html, $item->body_text),
            'attachment_ids' => $this->listedAttachments()
                ->filter(fn (IntakeAttachment $attachment): bool => $attachment->selected_for_upload && $attachment->isUploadable())
                ->map(fn (IntakeAttachment $attachment): string => (string) $attachment->id)
                ->values()
                ->all(),
        ]);
    }

    /**
     * Attachments shown in triage: everything except inline images (they are part of the
     * original view).
     *
     * @return Collection<int, IntakeAttachment>
     */
    private function listedAttachments(): Collection
    {
        return $this->listedAttachments ??= $this->getRecord()->attachments()
            ->where('is_inline', false)
            ->orderBy('id')
            ->get();
    }

    private static function attachmentDescription(IntakeAttachment $attachment): string
    {
        if ($attachment->type === AttachmentType::Reference) {
            return 'Link in der Original-Mail';
        }

        if ($attachment->error !== null) {
            return $attachment->error;
        }

        if ($attachment->storage_path === null) {
            return 'Der Anhang fehlt.';
        }

        return implode(', ', array_filter([
            $attachment->type->label(),
            $attachment->content_type,
            Number::fileSize($attachment->size, precision: 1),
        ]));
    }

    private static function address(?string $name, ?string $email): string
    {
        return match (true) {
            filled($name) && filled($email) && $name !== $email => "{$name} <{$email}>",
            filled($email) => (string) $email,
            filled($name) => (string) $name,
            default => 'Unbekannt',
        };
    }

    /**
     * @param  mixed  $recipients  to_json or cc_json
     * @return list<string>
     */
    private static function addresses(mixed $recipients): array
    {
        return collect(is_array($recipients) ? $recipients : [])
            ->filter(fn (mixed $recipient): bool => is_array($recipient))
            ->map(fn (array $recipient): string => self::address($recipient['name'] ?? null, $recipient['address'] ?? null))
            ->values()
            ->all();
    }

    /**
     * web_link comes from Graph. Only an https link is offered, never another scheme.
     */
    private function outlookUrl(): ?string
    {
        $url = SafeLinkConverter::safeHref((string) $this->getRecord()->web_link);

        return $url !== null && str_starts_with(strtolower($url), 'https://') ? $url : null;
    }

    private function canProcess(): bool
    {
        return $this->currentUser()->can('process', $this->getRecord());
    }

    private function currentUser(): User
    {
        $user = Auth::user();
        assert($user instanceof User);

        return $user;
    }
}
