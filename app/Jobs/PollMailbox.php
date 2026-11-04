<?php

namespace App\Jobs;

use App\Enums\ConnectionStatus;
use App\Intake\MailboxPoller;
use App\Microsoft\GraphException;
use App\Microsoft\MicrosoftConnectionBroken;
use App\Microsoft\MicrosoftTokenUnavailable;
use App\Microsoft\UserTokens;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Imports the mails one user marked with "ClickUp". At most one per user at a time.
 *
 * The payload is the user id only, never a token (R11c). timeout stays below the queue's
 * retry_after (config/queue.php), so a slow run is never picked up twice. Expected failures
 * (connection, token, Graph) end the job normally: the next poll follows in two minutes, and
 * failed_jobs is not filled every minute by a lasting problem.
 */
class PollMailbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [30];

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    /**
     * Upper bound for the unique lock if a worker dies without releasing it.
     */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(MailboxPoller $poller, UserTokens $tokens): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null || ! $user->active || $user->ms_connection_status !== ConnectionStatus::Connected) {
            return;
        }

        try {
            $poller->poll($user);
        } catch (MicrosoftConnectionBroken) {
            // Status is broken now (or was already), the next sign-in repairs it. No item touched.
        } catch (MicrosoftTokenUnavailable $e) {
            Log::warning('Intake poll skipped, no Microsoft access token.', [
                'user_id' => $this->userId,
                'reason' => $e->reason,
            ]);
        } catch (GraphException $e) {
            Log::warning('Intake poll could not read the mail list.', [
                'user_id' => $this->userId,
                'exception' => $e::class,
                'status' => $e->status,
            ]);

            // The cached access token was rejected: the next run renews it.
            if ($e->status === 401) {
                $tokens->forget($user);
            }
        }
    }
}
