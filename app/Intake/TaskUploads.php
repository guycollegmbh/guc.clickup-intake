<?php

namespace App\Intake;

use App\ClickUp\ClickUpClient;
use App\ClickUp\ClickUpException;
use App\ClickUp\ClickUpNotConnected;
use App\ClickUp\UserClickUp;
use App\Enums\IntakeStatus;
use App\Models\IntakeAttachment;
use App\Models\IntakeItem;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Second half of "Task erstellen", run by the queue (UploadTaskAttachments): uploads the
 * selected attachments one after the other, adds the comment once, then finishes the item.
 *
 * Runs with the token of processed_by, the user who created the task or retried the upload
 * (E2). All done: created and "ClickUp ✓" (R10). Anything missing: failed with the task id,
 * a partial success that can be retried (round 1, point 5). Attachments already uploaded and
 * a comment already added are never sent twice.
 *
 * Unclear outcomes (connection lost, 503, 504) are marked. Before such an upload or comment is
 * sent again, the task in ClickUp is checked: attachments by name and size, the comment by its
 * text. Found counts as done. If the check itself fails, the entry is skipped and named in the
 * error text (E10).
 *
 * With a deadline, no further upload is started when it might not finish in time. run() then
 * returns false and the job queues a follow-up for the rest.
 */
class TaskUploads
{
    /**
     * Seconds kept free before the deadline besides the upload timeout of the client.
     */
    public const RESERVE_SECONDS = 30;

    public function __construct(
        private ItemTransitions $transitions,
        private UserClickUp $clickUp,
        private TriageActions $actions,
    ) {}

    /**
     * False if it stopped at the deadline with attachments left, true otherwise.
     */
    public function run(IntakeItem $item, ?CarbonInterface $deadline = null): bool
    {
        $item->refresh();

        if ($item->status !== IntakeStatus::Creating || $item->clickup_task_id === null) {
            return true;
        }

        $actor = $item->processedBy;

        try {
            $client = $actor === null ? throw new ClickUpNotConnected(0, 'no_actor') : $this->clickUp->client($actor);
        } catch (ClickUpNotConnected) {
            $this->transitions->markUploadFailed($item, 'Anhänge nicht hochgeladen: Die ClickUp-Verbindung von '
                .($actor?->name ?? 'unbekannt').' ist nicht verfügbar. Bitte ClickUp verbinden und erneut hochladen.');

            return true;
        }

        $failed = [];
        $unchecked = [];
        $remote = null;
        $started = 0;

        foreach ($this->pending($item) as $attachment) {
            if ($deadline !== null && $started > 0
                && now()->addSeconds(ClickUpClient::UPLOAD_TIMEOUT_SECONDS + self::RESERVE_SECONDS)->greaterThan($deadline)) {
                $this->transitions->touchCreating($item);

                return false;
            }

            $started++;

            if (! $attachment->isUploadable()) {
                $attachment->forceFill(['upload_error' => 'Der Anhang ist nicht gespeichert.'])->save();
                $failed[] = $attachment->name;

                continue;
            }

            if ($attachment->upload_uncertain) {
                $remote ??= $this->remoteAttachments($client, $item);

                if ($remote === false) {
                    $unchecked[] = $attachment->name;

                    continue;
                }

                if ($this->takeMatch($remote, $attachment)) {
                    $attachment->forceFill(['uploaded_to_clickup' => true, 'upload_uncertain' => false, 'upload_error' => null])->save();

                    continue;
                }
            }

            try {
                $client->attach($item->clickup_task_id, Storage::disk(AttachmentStore::DISK)->path($attachment->storage_path), $attachment->name);
                $attachment->forceFill(['uploaded_to_clickup' => true, 'upload_uncertain' => false, 'upload_error' => null])->save();
            } catch (Throwable $e) {
                $this->report('ClickUp attachment upload failed.', $item, $e, ['attachment_id' => $attachment->id]);
                $attachment->forceFill([
                    'upload_error' => self::uploadError($e),
                    'upload_uncertain' => $e instanceof ClickUpException && $e->isUncertain(),
                ])->save();
                $failed[] = $attachment->name;
            }

            $this->transitions->touchCreating($item);
        }

        $comment = $this->addComment($item, $actor, $client);

        if ($failed === [] && $unchecked === [] && $comment === self::COMMENT_DONE) {
            if ($this->transitions->markCreated($item)) {
                $this->actions->syncCategory($item);
            }

            return true;
        }

        $this->transitions->markUploadFailed($item, self::failureText($failed, $unchecked, $comment));

        return true;
    }

    /**
     * Error text when the job stopped (failed, timed out): names what is still missing.
     */
    public static function abortedText(IntakeItem $item): string
    {
        $missing = $item->attachments()
            ->where('selected_for_upload', true)
            ->where('uploaded_to_clickup', false)
            ->orderBy('id')
            ->pluck('name')
            ->all();

        return $missing === []
            ? ItemTransitions::ABORTED_UPLOAD_ERROR
            : ItemTransitions::ABORTED_UPLOAD_ERROR.' Nicht hochgeladen: '.self::quoted($missing).'.';
    }

    /**
     * @return list<array{name: string, size: int}>|false false if ClickUp could not be asked
     */
    private function remoteAttachments(ClickUpClient $client, IntakeItem $item): array|false
    {
        try {
            return $client->taskAttachments($item->clickup_task_id);
        } catch (Throwable $e) {
            $this->report('Checking the attachments of a ClickUp task failed.', $item, $e);

            return false;
        }
    }

    /**
     * Removes and reports the first remote attachment with the same name and size, so two
     * equal local attachments need two remote ones.
     *
     * @param  list<array{name: string, size: int}>  $remote
     */
    private function takeMatch(array &$remote, IntakeAttachment $attachment): bool
    {
        foreach ($remote as $index => $candidate) {
            if ($candidate['name'] === $attachment->name && $candidate['size'] === (int) $attachment->size) {
                unset($remote[$index]);

                return true;
            }
        }

        return false;
    }

    /**
     * Selected and not uploaded yet, in the order of the mail.
     *
     * @return iterable<IntakeAttachment>
     */
    private function pending(IntakeItem $item): iterable
    {
        return $item->attachments()
            ->where('selected_for_upload', true)
            ->where('uploaded_to_clickup', false)
            ->orderBy('id')
            ->get();
    }

    private const COMMENT_DONE = 'done';

    private const COMMENT_MISSING = 'missing';

    private const COMMENT_UNCHECKED = 'unchecked';

    /**
     * Adds the comment if wanted and not added yet. After an unclear attempt, the comments of
     * the task are checked first.
     */
    private function addComment(IntakeItem $item, User $actor, ClickUpClient $client): string
    {
        if (! $item->clickup_comment_requested || $item->clickup_comment_added_at !== null) {
            return self::COMMENT_DONE;
        }

        if ($item->clickup_comment_uncertain) {
            try {
                $texts = $client->commentTexts($item->clickup_task_id);
            } catch (Throwable $e) {
                $this->report('Checking the comments of a ClickUp task failed.', $item, $e);

                return self::COMMENT_UNCHECKED;
            }

            if (collect($texts)->contains(fn (string $text): bool => self::isOwnComment($text, $item))) {
                return $this->commentAdded($item);
            }
        }

        try {
            $client->comment($item->clickup_task_id, self::commentText($item, $actor));
        } catch (Throwable $e) {
            $this->report('ClickUp comment failed.', $item, $e);
            IntakeItem::query()->whereKey($item->getKey())->update([
                'clickup_comment_uncertain' => $e instanceof ClickUpException && $e->isUncertain(),
            ]);
            $item->refresh();

            return self::COMMENT_MISSING;
        }

        return $this->commentAdded($item);
    }

    private function commentAdded(IntakeItem $item): string
    {
        IntakeItem::query()->whereKey($item->getKey())->update([
            'clickup_comment_added_at' => now(),
            'clickup_comment_uncertain' => false,
        ]);
        $item->refresh();

        return self::COMMENT_DONE;
    }

    /**
     * Plain text, no mail content besides sender and link (E10). Sender name and address come
     * from the mail: control and bidi characters are removed, so they cannot add lines.
     */
    public static function commentText(IntakeItem $item, User $actor): string
    {
        return self::commentBody($item)."\n".self::COMMENT_FOOTER.self::clean($actor->name, 100);
    }

    private const COMMENT_FOOTER = 'Erstellt über ClickUp-Intake von ';

    /**
     * The comment without the last line: a retry by another user changes only that line.
     */
    private static function isOwnComment(string $text, IntakeItem $item): bool
    {
        $text = trim(str_replace("\r\n", "\n", $text));

        return str_starts_with($text, self::commentBody($item)."\n".self::COMMENT_FOOTER);
    }

    private static function commentBody(IntakeItem $item): string
    {
        $name = self::clean($item->from_name, 100);
        $email = self::clean($item->from_email, 254);
        $sender = match (true) {
            $name !== '' && $email !== '' => "{$name} <{$email}>",
            $email !== '' => $email,
            $name !== '' => $name,
            default => 'unbekannt',
        };
        $link = SafeLinkConverter::safeHref((string) $item->web_link);

        return implode("\n", array_filter([
            'Von: '.$sender,
            $item->received_at === null ? null : 'Empfangen: '.$item->received_at->timezone(config('intake.display_timezone'))->format('d.m.Y H:i'),
            $link !== null && str_starts_with(strtolower($link), 'https://') ? 'In Outlook: '.$link : null,
        ]));
    }

    /**
     * Control and bidi characters become spaces (as in MessageImporter::safeName()), whitespace
     * is collapsed, the length limited.
     */
    private static function clean(?string $value, int $limit): string
    {
        $value = preg_replace('/[\x{0000}-\x{001F}\x{007F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', ' ', (string) $value) ?? '';

        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $value)), $limit, '');
    }

    /**
     * @param  list<string>  $failed  names of the attachments not uploaded
     * @param  list<string>  $unchecked  names of unclear uploads that could not be checked
     */
    private static function failureText(array $failed, array $unchecked, string $comment): string
    {
        $parts = ['Der Task wurde erstellt.'];

        if ($failed !== []) {
            $parts[] = (count($failed) === 1 ? 'Nicht hochgeladen ist der Anhang ' : 'Nicht hochgeladen sind die Anhänge ')
                .self::quoted($failed).'.';
        }

        if ($unchecked !== []) {
            $parts[] = 'Nicht prüfbar war, ob '.(count($unchecked) === 1 ? 'der Anhang ' : 'die Anhänge ')
                .self::quoted($unchecked).' in ClickUp angekommen '.(count($unchecked) === 1 ? 'ist.' : 'sind.');
        }

        if ($comment === self::COMMENT_MISSING) {
            $parts[] = 'Der Kommentar mit Absender und Mail-Link fehlt.';
        }

        if ($comment === self::COMMENT_UNCHECKED) {
            $parts[] = 'Nicht prüfbar war, ob der Kommentar in ClickUp angekommen ist.';
        }

        $parts[] = 'Bitte «Fehlende Anhänge erneut hochladen» wählen.';

        return implode(' ', $parts);
    }

    /**
     * @param  list<string>  $names
     */
    private static function quoted(array $names): string
    {
        return implode(', ', array_map(fn (string $name): string => "«{$name}»", $names));
    }

    private static function uploadError(Throwable $e): string
    {
        return match (true) {
            $e instanceof ClickUpException && $e->isUncertain() => 'Unklar, ob der Anhang hochgeladen wurde. Bitte in ClickUp prüfen.',
            $e instanceof ClickUpException && $e->status === 401 => 'Die Verbindung zu ClickUp ist unterbrochen.',
            $e instanceof ClickUpException && $e->status === 413 => 'Der Anhang ist für ClickUp zu gross.',
            $e instanceof ClickUpException => "ClickUp hat mit Fehler {$e->status} geantwortet.",
            default => 'Der Anhang konnte nicht hochgeladen werden.',
        };
    }

    /**
     * Ids, exception class and status only (R11c).
     *
     * @param  array<string, mixed>  $context
     */
    private function report(string $message, IntakeItem $item, Throwable $e, array $context = []): void
    {
        Log::warning($message, [
            'intake_item_id' => $item->id,
            ...$context,
            'exception' => $e::class,
            'status' => $e instanceof ClickUpException ? $e->status : null,
        ]);
    }
}
