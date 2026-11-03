<?php

namespace App\Intake;

use App\ClickUp\ClickUpClient;
use App\ClickUp\ClickUpException;
use App\ClickUp\ClickUpNotConnected;
use App\ClickUp\UserClickUp;
use App\Jobs\SyncOutlookCategory;
use App\Jobs\UploadTaskAttachments;
use App\Microsoft\GraphException;
use App\Microsoft\MicrosoftConnectionBroken;
use App\Microsoft\MicrosoftTokenUnavailable;
use App\Microsoft\UserTokens;
use App\Models\IntakeItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Triage actions: create a ClickUp task, retry missing uploads, discard and forward to support.
 *
 * Every action checks the policy itself (R12), whoever calls it. Status changes go through
 * ItemTransitions, the Outlook category follows the result (R10). A failed category update
 * leaves outlook_category_synced_at null, the poll repeats it (E7). Forwarding and creating a
 * task run under the same lock per item, so they never run at the same time.
 */
class TriageActions
{
    /**
     * Held around check, request and status change of a forward or a task creation, so a second
     * click or a second user cannot send the mail twice or create a second task. Longer than
     * the Graph or ClickUp timeout plus 429 waits.
     */
    private const ITEM_LOCK_SECONDS = 300;

    public const PRIORITIES = [1 => 'Dringend', 2 => 'Hoch', 3 => 'Normal', 4 => 'Niedrig'];

    public function __construct(
        private ItemTransitions $transitions,
        private UserTokens $tokens,
        private UserClickUp $clickUp,
        private RuleMatcher $rules,
    ) {}

    public static function lockName(int $itemId): string
    {
        return 'intake:item:'.$itemId;
    }

    /**
     * The configured support address (INTAKE_SUPPORT_ADDRESS), null if missing or not an
     * address. The only possible recipient of a forward (R14).
     */
    public static function supportAddress(): ?string
    {
        $address = trim((string) config('intake.support_address'));

        return filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : null;
    }

    /**
     * Discards one item and updates Outlook right away. The item is reloaded first: callers
     * may pass a model with partial columns (table) or a stale status.
     */
    public function discard(IntakeItem $item, User $actor): TriageResult
    {
        $item->refresh();
        Gate::forUser($actor)->authorize('process', $item);

        if (! $this->transitions->discard($item, $actor)) {
            return TriageResult::Skipped;
        }

        return $this->syncCategory($item);
    }

    /**
     * Bulk discard: only the status change happens in the request, the Outlook category is
     * set by one queued job per item (E9). Items the actor may not process (any more) are
     * skipped and counted, they do not stop the others.
     *
     * @param  iterable<IntakeItem>  $items
     * @return array{discarded: int, skipped: int}
     */
    public function discardMany(iterable $items, User $actor): array
    {
        $discarded = 0;
        $skipped = 0;

        foreach ($items as $item) {
            $item->refresh();

            if (Gate::forUser($actor)->allows('process', $item) && $this->transitions->discard($item, $actor)) {
                SyncOutlookCategory::dispatch($item->id);
                $discarded++;
            } else {
                $skipped++;
            }
        }

        return ['discarded' => $discarded, 'skipped' => $skipped];
    }

    /**
     * Forwards the mail with the token of the mailbox owner (only that mailbox has the mail,
     * E1), also when an admin acts for someone else. The comment names the acting user.
     * Graph first, status second: if the request fails, nothing is marked as forwarded.
     * The whole sequence runs under a lock per item.
     */
    public function forwardToSupport(IntakeItem $item, User $actor): TriageResult
    {
        $address = self::supportAddress() ?? throw new LogicException('No support address configured.');
        $lock = Cache::lock(self::lockName($item->id), self::ITEM_LOCK_SECONDS);

        if (! $lock->get()) {
            return TriageResult::Skipped;
        }

        try {
            $item->refresh();
            Gate::forUser($actor)->authorize('forward', $item);
            $owner = $item->loadMissing('user')->user;

            try {
                $this->tokens->graphFor($owner)->post('me/messages/'.rawurlencode($item->graph_message_id).'/forward', [
                    'comment' => 'Über ClickUp-Intake weitergeleitet von '.e($actor->name),
                    'toRecipients' => [['emailAddress' => ['address' => $address]]],
                ]);
            } catch (Throwable $e) {
                $this->report('Forwarding an intake mail failed.', $item, $owner, $e);
                $this->transitions->markFailed($item, self::forwardError($e));

                return TriageResult::Failed;
            }

            if (! $this->transitions->markForwarded($item, $actor)) {
                // Forwarded, but someone else processed the item in the meantime.
                Log::warning('Intake mail forwarded, but its status changed in the meantime.', ['intake_item_id' => $item->id]);

                return TriageResult::Skipped;
            }
        } finally {
            $lock->release();
        }

        return $this->syncCategory($item);
    }

    /**
     * "Task erstellen" (E2, E10): the task is created synchronously with the acting user's
     * token, the uploads follow in the queue (UploadTaskAttachments). The item is creating
     * from before the request until the uploads are done. If ClickUp fails, the item is failed
     * with a text, after a lost connection, 503 or 504 the text says the task may exist.
     *
     * $draft is the validated form state of the triage page: task_name, description,
     * attachment_ids, list_id, assignee_ids, due_date (Y-m-d), priority (1 to 4) and
     * add_comment.
     *
     * @param  array<string, mixed>  $draft
     */
    public function createTask(IntakeItem $item, User $actor, array $draft): TriageResult
    {
        $listId = trim((string) ($draft['list_id'] ?? ''));
        $fields = self::taskFields($draft);

        if ($listId === '') {
            throw new InvalidArgumentException('A ClickUp list is required.');
        }

        $lock = Cache::lock(self::lockName($item->id), self::ITEM_LOCK_SECONDS);

        if (! $lock->get()) {
            return TriageResult::Skipped;
        }

        try {
            $item->refresh();
            Gate::forUser($actor)->authorize('createTask', $item);

            try {
                $client = $this->clickUp->client($actor, interactive: true);
            } catch (ClickUpNotConnected) {
                return TriageResult::NotConnected;
            }

            // A busy token stops here, before the item changes (E10).
            if (! $client->waitForCapacity()) {
                return TriageResult::Busy;
            }

            $listLabel = $this->clickUp->cachedLists($actor)[$listId] ?? null;
            $started = DB::transaction(function () use ($item, $actor, $draft): bool {
                if (! $this->transitions->startCreating($item, $actor, (bool) ($draft['add_comment'] ?? false))) {
                    return false;
                }

                $this->selectAttachments($item, $draft['attachment_ids'] ?? []);

                return true;
            });

            if (! $started) {
                return TriageResult::Skipped;
            }

            try {
                $task = $client->createTask($listId, $fields);
            } catch (Throwable $e) {
                $this->reportClickUp('Creating a ClickUp task failed.', $item, $e);
                $this->transitions->failCreating($item, self::createError($e));

                return TriageResult::Failed;
            }

            if (! $this->recordTask($item, $task, $listId)) {
                return TriageResult::Failed;
            }

            $actor->forceFill(['last_clickup_list_id' => $listId, 'last_clickup_list_label' => $listLabel])->save();
        } finally {
            $lock->release();
        }

        $this->dispatchUploads($item);

        return TriageResult::Done;
    }

    /**
     * "Fehlende Anhänge erneut hochladen" on a partial success: back to creating and the same
     * upload job, now with the acting user's token. Uploaded attachments and an added comment
     * are not sent again.
     */
    public function retryUpload(IntakeItem $item, User $actor): TriageResult
    {
        $lock = Cache::lock(self::lockName($item->id), self::ITEM_LOCK_SECONDS);

        if (! $lock->get()) {
            return TriageResult::Skipped;
        }

        try {
            $item->refresh();
            Gate::forUser($actor)->authorize('retryUpload', $item);

            if (! $this->clickUp->isConnected($actor)) {
                return TriageResult::NotConnected;
            }

            if (! $this->transitions->retryUpload($item, $actor)) {
                return TriageResult::Skipped;
            }
        } finally {
            $lock->release();
        }

        $this->dispatchUploads($item);

        return TriageResult::Done;
    }

    /**
     * Pre-selection of the ClickUp fields of the triage page (E10, R2). List: the first
     * matching rule, otherwise the acting user's last list, each only if the acting user can see
     * it. Assignees: from the rule, otherwise the mailbox owner, only members of the workspace.
     *
     * @param  array<string, string>  $lists  lists the acting user sees, id => label
     * @param  array<int, string>  $members  members of the workspace, id => name
     * @return array{list_id: string|null, assignee_ids: list<string>, due_date: null, priority: null, add_comment: bool}
     */
    public function taskDefaults(IntakeItem $item, User $actor, array $lists, array $members): array
    {
        $rule = $this->rules->match($item);

        $listId = collect([$rule?->clickup_list_id, $actor->last_clickup_list_id])
            ->first(fn (?string $id): bool => $id !== null && array_key_exists($id, $lists));

        $assignees = $rule !== null && filled($rule->assignee_ids)
            ? (array) $rule->assignee_ids
            : array_filter([$item->loadMissing('user')->user?->clickup_member_id]);

        return [
            'list_id' => $listId === null ? null : (string) $listId,
            'assignee_ids' => array_values(array_map('strval', array_filter(
                $assignees,
                fn (mixed $id): bool => is_numeric($id) && array_key_exists((int) $id, $members),
            ))),
            'due_date' => null,
            'priority' => null,
            'add_comment' => true,
        ];
    }

    /**
     * Fields for ClickUp's "Create Task" (E10): markdown_content, priority 1 (urgent) to
     * 4 (low) or none, due date as 12:00 Europe/Zurich in ms without time, so the day is the
     * same in every timezone.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public static function taskFields(array $draft): array
    {
        $name = trim((string) ($draft['task_name'] ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException('A task name is required.');
        }

        $fields = [
            'name' => $name,
            'markdown_content' => (string) ($draft['description'] ?? ''),
        ];

        $assignees = array_values(array_unique(array_map('intval', array_filter(
            (array) ($draft['assignee_ids'] ?? []),
            fn (mixed $id): bool => is_numeric($id) && (int) $id > 0,
        ))));

        if ($assignees !== []) {
            $fields['assignees'] = $assignees;
        }

        $priority = $draft['priority'] ?? null;

        if (is_numeric($priority) && array_key_exists((int) $priority, self::PRIORITIES)) {
            $fields['priority'] = (int) $priority;
        }

        if (filled($draft['due_date'] ?? null)) {
            $fields['due_date'] = CarbonImmutable::parse((string) $draft['due_date'], config('intake.display_timezone'))
                ->setTime(12, 0)
                ->getTimestampMs();
            $fields['due_date_time'] = false;
        }

        return $fields;
    }

    /**
     * Stores the selection of the triage page (R8 attachments only, IntakeAttachment::
     * isUploadable()). Everything starts as not uploaded.
     *
     * @param  mixed  $ids  attachment ids from the form
     */
    private function selectAttachments(IntakeItem $item, mixed $ids): void
    {
        $selected = array_map('intval', array_filter((array) $ids, 'is_numeric'));

        foreach ($item->attachments()->get() as $attachment) {
            $attachment->forceFill([
                'selected_for_upload' => in_array($attachment->id, $selected, true) && ! $attachment->is_inline && $attachment->isUploadable(),
                'uploaded_to_clickup' => false,
                'upload_error' => null,
            ])->save();
        }
    }

    /**
     * Stores the created task on the item. If that fails, the task exists in ClickUp but the
     * item does not know it: the id and URL go to the log (no secret), the item becomes failed
     * with a text naming the URL, and no upload starts.
     *
     * @param  array{id: string, url: string}  $task
     */
    private function recordTask(IntakeItem $item, array $task, string $listId): bool
    {
        try {
            if ($this->transitions->recordTask($item, $task['id'], $task['url'], $listId)) {
                return true;
            }

            $exception = null;
        } catch (Throwable $e) {
            $exception = $e::class;
        }

        Log::error('ClickUp task created, but not stored on the intake item.', [
            'intake_item_id' => $item->id,
            'clickup_task_id' => $task['id'],
            'clickup_task_url' => $task['url'],
            'exception' => $exception,
        ]);

        try {
            $this->transitions->markTaskNotRecorded($item, 'Der Task wurde angelegt, konnte aber nicht gespeichert werden: '.$task['url']);
        } catch (Throwable $e) {
            Log::error('Intake item could not be marked as failed after an unstored task.', [
                'intake_item_id' => $item->id,
                'exception' => $e::class,
            ]);
        }

        return false;
    }

    /**
     * A queue failure leaves the item in creating, failStaleCreating() makes it a partial
     * success that can be retried.
     */
    private function dispatchUploads(IntakeItem $item): void
    {
        try {
            UploadTaskAttachments::dispatch($item->id);
        } catch (Throwable $e) {
            Log::warning('ClickUp upload job could not be queued.', ['intake_item_id' => $item->id, 'exception' => $e::class]);
        }
    }

    private static function createError(Throwable $e): string
    {
        return match (true) {
            $e instanceof ClickUpException && $e->isUncertain() => 'Unklar, ob der Task angelegt wurde. Bitte zuerst in ClickUp prüfen.',
            $e instanceof ClickUpException && $e->errorCode === ClickUpClient::LOCAL_RATE_LIMIT => 'Task nicht erstellt: ClickUp ist ausgelastet, bitte gleich erneut versuchen.',
            $e instanceof ClickUpException && $e->status === 401 => 'Task nicht erstellt: Die Verbindung zu ClickUp ist unterbrochen. Bitte ClickUp neu verbinden.',
            $e instanceof ClickUpException && in_array($e->status, [403, 404], true) => 'Task nicht erstellt: Die gewählte List wurde in ClickUp nicht gefunden oder ist nicht freigegeben.',
            $e instanceof ClickUpException => "Task nicht erstellt: ClickUp hat mit Fehler {$e->status} geantwortet.",
            default => 'Task nicht erstellt. Details stehen im Log.',
        };
    }

    /**
     * Ids, exception class and status only (R11c).
     */
    private function reportClickUp(string $message, IntakeItem $item, Throwable $e): void
    {
        Log::warning($message, [
            'intake_item_id' => $item->id,
            'exception' => $e::class,
            'status' => $e instanceof ClickUpException ? $e->status : null,
        ]);
    }

    /**
     * Brings the Outlook category in line with the final status. Never throws: a failure
     * is logged and the poll repeats it (E7). A deactivated owner has no token any more,
     * the category stays as it is and retention deletes the item without sync (E9).
     */
    public function syncCategory(IntakeItem $item): TriageResult
    {
        $owner = $item->loadMissing('user')->user;

        if (! $owner->active) {
            return TriageResult::OwnerInactive;
        }

        try {
            return $this->transitions->syncCategory($item) ? TriageResult::Done : TriageResult::CategoryPending;
        } catch (Throwable $e) {
            $this->report('Outlook category of an intake mail not updated.', $item, $owner, $e);

            return TriageResult::CategoryPending;
        }
    }

    /**
     * Logs ids and the exception class only (R11c). A rejected access token is dropped, the
     * next call renews it.
     */
    private function report(string $message, IntakeItem $item, User $owner, Throwable $e): void
    {
        if ($e instanceof GraphException && $e->status === 401) {
            $this->tokens->forget($owner);
        }

        Log::warning($message, [
            'intake_item_id' => $item->id,
            'exception' => $e::class,
            'status' => $e instanceof GraphException ? $e->status : null,
        ]);
    }

    /**
     * After a lost connection, 503 or 504 the POST may have been executed: the user checks
     * Sent Items first instead of simply trying again.
     */
    private static function forwardError(Throwable $e): string
    {
        return match (true) {
            $e instanceof MicrosoftConnectionBroken && $e->reason === 'inactive' => 'Weiterleitung nicht möglich: Der Benutzer des Postfachs ist deaktiviert.',
            $e instanceof MicrosoftConnectionBroken => 'Weiterleitung fehlgeschlagen: Die Verbindung des Postfachs zu Microsoft 365 ist unterbrochen. Der Inhaber des Postfachs muss sich neu anmelden.',
            $e instanceof MicrosoftTokenUnavailable => 'Weiterleitung fehlgeschlagen: Microsoft 365 war nicht erreichbar. Bitte später erneut versuchen.',
            $e instanceof GraphException && in_array($e->status, [0, 503, 504], true) => 'Unklar, ob die Mail weitergeleitet wurde. Bitte zuerst in Outlook unter Gesendete Elemente prüfen.',
            $e instanceof GraphException && $e->isNotFound() => 'Weiterleitung fehlgeschlagen: Die Mail wurde in Outlook nicht mehr gefunden.',
            $e instanceof GraphException => "Weiterleitung fehlgeschlagen: Microsoft 365 hat mit Fehler {$e->status} geantwortet.",
            default => 'Weiterleitung fehlgeschlagen. Details stehen im Log.',
        };
    }
}
