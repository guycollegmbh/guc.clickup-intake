<?php

namespace App\Intake;

use App\Enums\AttachmentType;
use App\Enums\IntakeStatus;
use App\Microsoft\GraphClient;
use App\Microsoft\GraphException;
use App\Models\IntakeItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Imports one Graph message of a user's mailbox into an intake item (step 3, E6, E7, E8).
 *
 * Input is a message summary (SUMMARY_SELECT) from the poll or, later, from an ad-hoc import
 * (Phase C), which is why import() returns the item. Dedupe key is internetMessageId per user
 * (R7). A different mail with the same Message-ID (other id and other received time or sender)
 * gets its own item with the key "{internetMessageId}#{id}". Without internetMessageId the key
 * is "graph:{id}". The id is the immutable Graph id (Prefer IdType="ImmutableId").
 *
 * imported_at is set only when the item and all attachments are complete. A failed import
 * keeps imported_at null, is failed with a German text (visible, R6) and is retried after
 * next_import_at. A failed status from later steps has imported_at set and is never touched.
 *
 * Security: everything taken from the mail is hostile input. Body, body_text, subject,
 * addresses, attachment names and error texts containing names are only ever output escaped,
 * the HTML only in the sandboxed iframe (R13). Attachment names are normalised here, the
 * content type is determined from the stored bytes, downloads must always be sent as
 * attachment with nosniff.
 */
class MessageImporter
{
    public const SUMMARY_SELECT = 'id,internetMessageId,subject,from,receivedDateTime,categories';

    private const MESSAGE_SELECT = 'id,internetMessageId,subject,from,toRecipients,ccRecipients,receivedDateTime,webLink,body';

    /**
     * contentId exists on fileAttachment only. Graph rejects it unqualified with 400, the
     * qualified form is verified against the tenant (E8).
     */
    public const ATTACHMENT_SELECT = 'id,name,contentType,size,isInline,microsoft.graph.fileAttachment/contentId';

    private const PREFER_HTML_BODY = 'outlook.body-content-type="html"';

    private const MAX_ATTACHMENT_PAGES = 20;

    public function __construct(
        private AttachmentStore $store,
        private ItemTransitions $transitions,
    ) {}

    /**
     * Applies the dedupe table (E7, E8) and returns the item the mail belongs to.
     *
     * @param  array<string, mixed>  $summary  Graph message with at least SUMMARY_SELECT
     * @param  CarbonInterface|null  $listedAt  when the summary was read from Graph: a discarded
     *                                          item synced after that is not reopened
     */
    public function import(User $user, GraphClient $graph, array $summary, ?CarbonInterface $listedAt = null): IntakeItem
    {
        $graphId = (string) ($summary['id'] ?? '');

        if ($graphId === '') {
            throw new ImportFailed('Die Mail hat keine Graph-ID.');
        }

        [$key, $item] = $this->resolve($user, $summary);

        if ($item === null) {
            $item = new IntakeItem([
                ...$this->summaryFields($summary),
                'mailbox_upn' => $user->mailbox_upn,
                'graph_message_id' => $graphId,
                'internet_message_id' => $key,
            ]);
            $item->user()->associate($user);
            $item->save();
            $this->importContent($item, $graph, clear: false);

            return $item;
        }

        // Import not complete (aborted or failed): again once the wait is over.
        if ($item->imported_at === null && $item->status->isProcessable()) {
            if (($item->next_import_at === null || ! $item->next_import_at->isFuture())
                && $this->transitions->claimForImport($item)) {
                $this->importContent($item, $graph, clear: true);
            }

            return $item;
        }

        if (! $item->status->isFinal()) {
            return $item;
        }

        if ($item->outlook_category_synced_at === null) {
            $this->transitions->syncCategory($item);
        } elseif ($item->graph_message_id !== $graphId) {
            // A copy of the same mail: give it the category, keep the item as it is.
            $this->transitions->alignCopy($item, $graphId);
        } elseif ($item->status === IntakeStatus::Discarded
            && ($listedAt === null || $item->outlook_category_synced_at->lessThan($listedAt))) {
            $this->transitions->backToPending($item);
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array{0: string, 1: IntakeItem|null}
     */
    private function resolve(User $user, array $summary): array
    {
        $graphId = (string) $summary['id'];
        $messageId = trim((string) ($summary['internetMessageId'] ?? ''));

        if ($messageId === '') {
            $key = 'graph:'.$graphId;

            return [$key, $this->find($user, $key)];
        }

        $ownKey = $messageId.'#'.$graphId;

        if (($own = $this->find($user, $ownKey)) !== null) {
            return [$ownKey, $own];
        }

        $existing = $this->find($user, $messageId);

        if ($existing !== null && $this->isOtherMail($existing, $summary)) {
            Log::notice('Same Message-ID, different mail: imported as a separate item.', [
                'user_id' => $user->id,
                'intake_item_id' => $existing->id,
            ]);

            return [$ownKey, null];
        }

        return [$messageId, $existing];
    }

    private function find(User $user, string $key): ?IntakeItem
    {
        return IntakeItem::query()
            ->where('user_id', $user->id)
            ->where('internet_message_id', $key)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function isOtherMail(IntakeItem $existing, array $summary): bool
    {
        if ($existing->graph_message_id === (string) $summary['id']) {
            return false;
        }

        $received = $this->date($summary['receivedDateTime'] ?? null);
        $sameReceived = $received !== null && $existing->received_at !== null
            && $existing->received_at->getTimestamp() === $received->getTimestamp();
        $sameSender = Str::lower((string) $existing->from_email)
            === Str::lower((string) ($summary['from']['emailAddress']['address'] ?? ''));

        return ! $sameReceived || ! $sameSender;
    }

    /**
     * Loads body and attachments of an item that is pending and not imported. The stored
     * graph_message_id is used, it does not follow copies of the mail.
     */
    private function importContent(IntakeItem $item, GraphClient $graph, bool $clear): void
    {
        try {
            if ($clear) {
                $this->store->clear($item);
            }

            $path = 'me/messages/'.rawurlencode($item->graph_message_id);
            $message = $graph->get($path, ['$select' => self::MESSAGE_SELECT], [self::PREFER_HTML_BODY]);

            $item->fill([...$this->summaryFields($message), ...$this->contentFields($message)]);
            $item->save();

            $this->importAttachments($item, $graph, $path);

            $item->forceFill([
                'imported_at' => now(),
                'import_attempts' => 0,
                'next_import_at' => null,
                'error' => null,
            ])->save();
        } catch (Throwable $e) {
            Log::warning('Intake import failed.', [
                'intake_item_id' => $item->id,
                'user_id' => $item->user_id,
                'exception' => $e::class,
                'graph_status' => $e instanceof GraphException ? $e->status : null,
            ]);

            $this->transitions->markImportFailed($item, $this->errorText($e));
        }
    }

    private function importAttachments(IntakeItem $item, GraphClient $graph, string $messagePath): void
    {
        $next = $messagePath.'/attachments';
        $query = ['$select' => self::ATTACHMENT_SELECT];
        $budget = (int) config('intake.item_max_mb') * 1024 * 1024;

        for ($page = 1; $next !== null; $page++) {
            if ($page > self::MAX_ATTACHMENT_PAGES) {
                throw new ImportFailed('Die Mail hat zu viele Anhänge.');
            }

            $response = $graph->get($next, $query);

            foreach ($response['value'] ?? [] as $attachment) {
                $budget -= $this->importAttachment($item, $graph, $messagePath, $attachment, $budget);
            }

            $next = $response['@odata.nextLink'] ?? null;
            $query = [];
        }
    }

    /**
     * @param  array<string, mixed>  $data  Graph attachment
     * @param  int  $budget  bytes still allowed for this mail (intake.item_max_mb)
     * @return int bytes stored
     */
    private function importAttachment(IntakeItem $item, GraphClient $graph, string $messagePath, array $data, int $budget): int
    {
        $type = match ($data['@odata.type'] ?? null) {
            '#microsoft.graph.fileAttachment' => AttachmentType::File,
            '#microsoft.graph.itemAttachment' => AttachmentType::Item,
            '#microsoft.graph.referenceAttachment' => AttachmentType::Reference,
            default => throw new ImportFailed('Die Mail hat einen unbekannten Anhangstyp.'),
        };

        $name = self::safeName((string) ($data['name'] ?? ''));
        $contentId = trim((string) ($data['contentId'] ?? ''), " \t<>") ?: null;

        if ($type === AttachmentType::Item && ! Str::endsWith(Str::lower($name), '.eml')) {
            $name .= '.eml';
        }

        $attributes = [
            'type' => $type,
            'graph_attachment_id' => (string) $data['id'],
            'name' => $name,
            // Replaced with the detected type once the file is stored.
            'content_type' => $type === AttachmentType::Item ? 'message/rfc822' : null,
            'size' => max(0, (int) ($data['size'] ?? 0)),
            'content_id' => $contentId,
            'is_inline' => $type === AttachmentType::File && $this->isInline($item, $data, $contentId),
        ];

        // A cloud link: name only, no file, never downloaded (E7 variant a).
        if ($type === AttachmentType::Reference) {
            $item->attachments()->create($attributes);

            return 0;
        }

        $maxBytes = (int) config('intake.attachment_max_mb') * 1024 * 1024;

        if ($attributes['size'] > $maxBytes) {
            $this->store->reject($item, $attributes, $this->store->tooLargeMessage());

            return 0;
        }

        if ($attributes['size'] > $budget) {
            $mb = (int) config('intake.item_max_mb');
            $this->store->reject($item, $attributes, "Die Anhänge dieser Mail sind zusammen grösser als {$mb} MB. Dieser Anhang wurde nicht gespeichert.");

            return 0;
        }

        $attachment = $this->store->store(
            $item,
            $attributes,
            $graph->stream($messagePath.'/attachments/'.rawurlencode((string) $data['id']).'/$value'),
        );

        if ($attachment->error !== null && $attachment->error !== $this->store->tooLargeMessage()) {
            throw new ImportFailed("Der Anhang «{$attachment->name}» konnte nicht geladen werden.");
        }

        if ($type === AttachmentType::File && $attachment->storage_path !== null) {
            $attachment->content_type = $this->store->detectMimeType($attachment) ?? 'application/octet-stream';
        }

        $attachment->selected_for_upload = ! $attachment->is_inline && $attachment->error === null;
        $attachment->save();

        return $attachment->storage_path === null ? 0 : $attachment->size;
    }

    /**
     * Attachment names are shown in triage and used as download names. Removes control and
     * bidi override characters (a name like "a\u{202E}fdp.exe" would display as "aexe.pdf")
     * and path separators.
     */
    public static function safeName(string $name): string
    {
        $name = preg_replace('/[\x{0000}-\x{001F}\x{007F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $name) ?? '';
        $name = trim(str_replace(['/', '\\'], '_', $name));

        return $name === '' ? 'Anhang' : Str::limit($name, 200, '');
    }

    /**
     * Graph sometimes reports images referenced in the body as not inline, so a cid reference
     * in the HTML counts as well.
     *
     * @param  array<string, mixed>  $data
     */
    private function isInline(IntakeItem $item, array $data, ?string $contentId): bool
    {
        if (($data['isInline'] ?? false) === true) {
            return true;
        }

        return $contentId !== null && Str::contains((string) $item->body_html, 'cid:'.$contentId, ignoreCase: true);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function summaryFields(array $message): array
    {
        return [
            'subject' => $message['subject'] ?? null,
            'from_name' => $message['from']['emailAddress']['name'] ?? null,
            'from_email' => $message['from']['emailAddress']['address'] ?? null,
            'received_at' => $this->date($message['receivedDateTime'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function contentFields(array $message): array
    {
        $content = (string) ($message['body']['content'] ?? '');
        $isText = Str::lower((string) ($message['body']['contentType'] ?? 'html')) === 'text';

        return [
            'to_json' => $this->recipients($message['toRecipients'] ?? []),
            'cc_json' => $this->recipients($message['ccRecipients'] ?? []),
            'web_link' => $message['webLink'] ?? null,
            'body_html' => $isText ? nl2br(e($content)) : $content,
            'body_text' => $isText ? $content : self::htmlToText($content),
        ];
    }

    /**
     * @param  mixed  $recipients  Graph recipient list
     * @return list<array{name: string|null, address: string|null}>
     */
    private function recipients(mixed $recipients): array
    {
        if (! is_array($recipients)) {
            return [];
        }

        return array_values(array_map(fn (mixed $recipient): array => [
            'name' => $recipient['emailAddress']['name'] ?? null,
            'address' => $recipient['emailAddress']['address'] ?? null,
        ], array_filter($recipients, 'is_array')));
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc() : null;
    }

    /**
     * Plain text for search and preview, without a further package: drops head, style and
     * script, turns block ends into line breaks, strips tags and decodes entities.
     */
    public static function htmlToText(string $html): string
    {
        $replace = function (string $pattern, string $replacement, string $subject): string {
            return preg_replace($pattern, $replacement, $subject) ?? $subject;
        };

        $text = $replace('#<(head|style|script|title)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $text = $replace('#<br\s*/?>#i', "\n", $text);
        $text = $replace('#</(p|div|tr|li|h[1-6]|blockquote|table|pre)\s*>#i', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $text = $replace('/[ \t]+/', ' ', $text);
        $text = $replace('/ *\n */', "\n", $text);
        $text = $replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    private function errorText(Throwable $e): string
    {
        return match (true) {
            $e instanceof ImportFailed => 'Import fehlgeschlagen: '.$e->getMessage(),
            $e instanceof GraphException && $e->isNotFound() => 'Import fehlgeschlagen: Die Mail oder ein Anhang wurde in Outlook nicht mehr gefunden.',
            $e instanceof GraphException && $e->status === 0 => 'Import fehlgeschlagen: Microsoft 365 war nicht erreichbar.',
            $e instanceof GraphException => "Import fehlgeschlagen: Microsoft 365 hat mit Fehler {$e->status} geantwortet.",
            default => 'Import fehlgeschlagen. Details stehen im Log.',
        };
    }
}
