<?php

namespace App\Intake;

use App\Jobs\SyncOutlookCategory;
use App\Microsoft\GraphException;
use App\Microsoft\MicrosoftConnectionBroken;
use App\Microsoft\MicrosoftTokenUnavailable;
use App\Microsoft\UserTokens;
use App\Models\IntakeItem;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Triage actions without ClickUp: discard and forward to support (step 4).
 *
 * Every action checks the policy itself (R12), whoever calls it. Status changes go through
 * ItemTransitions, the Outlook category follows the result (R10). A failed category update
 * leaves outlook_category_synced_at null, the poll repeats it (E7).
 *
 * Step 5 adds createTask(IntakeItem $item, User $actor, array $draft) here: the triage page
 * passes the validated form state as $draft and contains no logic of its own.
 */
class TriageActions
{
    /**
     * Held around check, POST and status change of a forward, so a second click or a second
     * user cannot send the mail twice. Longer than the Graph timeout plus 429 waits.
     */
    private const FORWARD_LOCK_SECONDS = 300;

    public function __construct(
        private ItemTransitions $transitions,
        private UserTokens $tokens,
    ) {}

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
        $lock = Cache::lock('intake:forward:'.$item->id, self::FORWARD_LOCK_SECONDS);

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
