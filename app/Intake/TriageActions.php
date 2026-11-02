<?php

namespace App\Intake;

use App\Microsoft\GraphException;
use App\Microsoft\MicrosoftConnectionBroken;
use App\Microsoft\MicrosoftTokenUnavailable;
use App\Microsoft\UserTokens;
use App\Models\IntakeItem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Triage actions without ClickUp: discard and forward to support (step 4). "Create task"
 * follows in step 5.
 *
 * Every action checks the policy "process" itself (R12), whoever calls it. Status changes go
 * through ItemTransitions, the Outlook category follows the result (R10). A failed category
 * update leaves outlook_category_synced_at null, the poll repeats it (E7).
 */
class TriageActions
{
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
     * The item is reloaded first: callers may pass a model with partial columns (table) or a
     * stale status.
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
     * Forwards the mail with the token of the mailbox owner (only that mailbox has the mail,
     * E1), also when an admin acts for someone else. The comment names the acting user.
     * Graph first, status second: if the request fails, nothing is marked as forwarded.
     */
    public function forwardToSupport(IntakeItem $item, User $actor): TriageResult
    {
        $item->refresh();
        Gate::forUser($actor)->authorize('process', $item);

        $address = self::supportAddress() ?? throw new LogicException('No support address configured.');
        $owner = $item->loadMissing('user')->user;

        try {
            $this->tokens->graphFor($owner)->post('me/messages/'.rawurlencode($item->graph_message_id).'/forward', [
                'comment' => 'Über ClickUp-Intake weitergeleitet von '.e($actor->name),
                'toRecipients' => [['emailAddress' => ['address' => $address]]],
            ]);
        } catch (Throwable $e) {
            $this->report('Forwarding an intake mail failed.', $item, $owner, $e);
            $this->transitions->markFailed($item, $this->forwardError($e));

            return TriageResult::Failed;
        }

        if (! $this->transitions->markForwarded($item, $actor)) {
            // Forwarded, but someone else processed the item in the meantime.
            Log::warning('Intake mail forwarded, but its status changed in the meantime.', ['intake_item_id' => $item->id]);

            return TriageResult::Skipped;
        }

        return $this->syncCategory($item);
    }

    private function syncCategory(IntakeItem $item): TriageResult
    {
        try {
            return $this->transitions->syncCategory($item) ? TriageResult::Done : TriageResult::CategoryPending;
        } catch (Throwable $e) {
            $this->report('Outlook category of an intake mail not updated.', $item, $item->loadMissing('user')->user, $e);

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

    private function forwardError(Throwable $e): string
    {
        return match (true) {
            $e instanceof MicrosoftConnectionBroken => 'Weiterleitung fehlgeschlagen: Die Verbindung des Postfachs zu Microsoft 365 ist unterbrochen. Der Inhaber des Postfachs muss sich neu anmelden.',
            $e instanceof MicrosoftTokenUnavailable => 'Weiterleitung fehlgeschlagen: Microsoft 365 war nicht erreichbar. Bitte später erneut versuchen.',
            $e instanceof GraphException && $e->isNotFound() => 'Weiterleitung fehlgeschlagen: Die Mail wurde in Outlook nicht mehr gefunden.',
            $e instanceof GraphException && $e->status === 0 => 'Weiterleitung fehlgeschlagen: Microsoft 365 war nicht erreichbar. Bitte später erneut versuchen.',
            $e instanceof GraphException => "Weiterleitung fehlgeschlagen: Microsoft 365 hat mit Fehler {$e->status} geantwortet.",
            default => 'Weiterleitung fehlgeschlagen. Details stehen im Log.',
        };
    }
}
