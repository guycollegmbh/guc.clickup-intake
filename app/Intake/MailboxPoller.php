<?php

namespace App\Intake;

use App\Enums\IntakeStatus;
use App\Microsoft\GraphClient;
use App\Microsoft\GraphException;
use App\Microsoft\OutlookCategories;
use App\Microsoft\UserTokens;
use App\Models\IntakeItem;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finds the mails a user marked with the category "ClickUp" and hands each to the importer.
 *
 * Primary query: /me/messages with a category filter (all folders). If Graph rejects the
 * filter, the inbox of the last FALLBACK_DAYS days is read and filtered here. One failing
 * message never stops the others (R6). After DEADLINE_SECONDS no further message is started,
 * so the job ends well before its timeout (PollMailbox).
 */
class MailboxPoller
{
    public const PAGE_SIZE = 25;

    public const FALLBACK_PAGE_SIZE = 50;

    public const MAX_PAGES = 10;

    public const FALLBACK_DAYS = 7;

    public const DEADLINE_SECONDS = 240;

    public const ABORTED_IMPORT_ERROR = 'Der Import wurde abgebrochen.';

    /**
     * Graph error codes (status 400) that mean "this filter is not supported here".
     */
    public const FILTER_REJECTED_CODES = [
        'InefficientFilter',
        'ErrorInvalidRestriction',
        'ErrorInvalidUrlQueryFilter',
    ];

    public function __construct(
        private UserTokens $tokens,
        private MessageImporter $importer,
        private AttachmentStore $store,
        private ItemTransitions $transitions,
    ) {}

    /**
     * Throws MicrosoftConnectionBroken or MicrosoftTokenUnavailable before touching any item,
     * and GraphException if the mail list cannot be read.
     */
    public function poll(User $user): void
    {
        if (! $this->store->hasFreeSpace()) {
            Log::warning('Intake poll skipped, not enough free disk space for attachments.', ['user_id' => $user->id]);

            return;
        }

        $graph = $this->tokens->graphFor($user);
        $startedAt = now();

        $this->failAbortedImports($user);

        $errors = 0;

        foreach ($this->markedMessages($user, $graph) as $summary) {
            if ($startedAt->diffInSeconds(now()) >= self::DEADLINE_SECONDS) {
                Log::notice('Intake poll reached its time limit, the rest follows in the next run.', ['user_id' => $user->id]);

                break;
            }

            try {
                $this->importer->import($user, $graph, $summary, $startedAt);
            } catch (Throwable $e) {
                // The mail keeps its category, the next poll tries again.
                Log::warning('Intake poll could not handle a message.', [
                    'user_id' => $user->id,
                    'exception' => $e::class,
                    'graph_status' => $e instanceof GraphException ? $e->status : null,
                ]);
                $errors++;
            }
        }

        if ($errors > 0) {
            Log::warning('Intake poll finished with errors.', ['user_id' => $user->id, 'errors' => $errors]);
        }

        $user->ms_last_polled_at = now();
        $user->save();
    }

    /**
     * Imports that started but never finished (worker killed, timeout) are hidden from triage.
     * After AttachmentStore::STALE_AFTER_MINUTES they become failed imports with the usual wait,
     * their partial attachments are removed, so intake:orphans stays clean.
     */
    private function failAbortedImports(User $user): void
    {
        IntakeItem::query()
            ->where('user_id', $user->id)
            ->whereNull('imported_at')
            ->where('status', IntakeStatus::Pending->value)
            ->where('updated_at', '<', now()->subMinutes(AttachmentStore::STALE_AFTER_MINUTES))
            ->lazyById()
            ->each(function (IntakeItem $item): void {
                try {
                    $this->store->clear($item);
                } catch (Throwable $e) {
                    Log::warning('Attachments of an aborted import could not be removed.', [
                        'intake_item_id' => $item->id,
                        'exception' => $e::class,
                    ]);
                }

                $this->transitions->markImportFailed($item, self::ABORTED_IMPORT_ERROR);
            });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function markedMessages(User $user, GraphClient $graph): array
    {
        try {
            return $this->collect($user, $graph, 'me/messages', [
                '$filter' => "categories/any(c:c eq '".OutlookCategories::CLICKUP."')",
                '$select' => MessageImporter::SUMMARY_SELECT,
                '$top' => self::PAGE_SIZE,
            ], filterHere: false);
        } catch (GraphException $e) {
            if ($e->status !== 400 || ! in_array($e->graphCode, self::FILTER_REJECTED_CODES, true)) {
                throw $e;
            }

            Log::notice('Graph rejected the category filter, polling the inbox of the last days instead.', [
                'user_id' => $user->id,
                'graph_code' => $e->graphCode,
            ]);
        }

        return $this->collect($user, $graph, 'me/mailFolders/inbox/messages', [
            '$filter' => 'receivedDateTime ge '.now()->utc()->subDays(self::FALLBACK_DAYS)->format('Y-m-d\TH:i:s\Z'),
            '$orderby' => 'receivedDateTime desc',
            '$select' => MessageImporter::SUMMARY_SELECT,
            '$top' => self::FALLBACK_PAGE_SIZE,
        ], filterHere: true);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function collect(User $user, GraphClient $graph, string $path, array $query, bool $filterHere): array
    {
        $messages = [];
        $next = $path;

        for ($page = 1; $next !== null; $page++) {
            if ($page > self::MAX_PAGES) {
                Log::warning('Intake poll stopped at the page limit, further marked mails are not seen.', [
                    'user_id' => $user->id,
                ]);

                break;
            }

            $response = $graph->get($next, $query);

            foreach ($response['value'] ?? [] as $message) {
                $categories = array_filter($message['categories'] ?? [], 'is_string');

                // Outlook compares category names without case.
                if (! $filterHere || OutlookCategories::contains($categories, OutlookCategories::CLICKUP)) {
                    $messages[] = $message;
                }
            }

            $next = $response['@odata.nextLink'] ?? null;
            $query = [];
        }

        return $messages;
    }
}
