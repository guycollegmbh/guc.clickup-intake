<?php

namespace App\Models;

use App\Enums\IntakeStatus;
use App\Intake\AttachmentStore;
use Database\Factories\IntakeItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A mail imported from a user's mailbox, waiting for or done with triage.
 *
 * Only the imported mail fields are fillable. Status, result and processing fields are set
 * explicitly by the single place that performs status transitions (steps 3 to 5).
 *
 * Contract (E7):
 * - imported_at is set only once the item and all attachments are completely imported.
 *   A failed import keeps imported_at null, is failed with an error text and is retried after
 *   next_import_at (E8). Triage shows imported and failed items (scope visibleInTriage()).
 * - Every status change sets outlook_category_synced_at to null. It is set only after the
 *   Outlook category was updated successfully for the new status (R10). Retention relies on it.
 *
 * Delete only through Eloquent: after the commit, the deleted event removes intake/{id} with all
 * files (R8).
 */
#[Fillable([
    'mailbox_upn',
    'graph_message_id',
    'internet_message_id',
    'subject',
    'from_name',
    'from_email',
    'to_json',
    'cc_json',
    'received_at',
    'web_link',
    'body_html',
    'body_text',
])]
class IntakeItem extends Model
{
    /** @use HasFactory<IntakeItemFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'pending',
    ];

    protected static function booted(): void
    {
        // After the commit: a rolled back delete must keep its files. Runs at once without a
        // transaction.
        static::deleted(function (IntakeItem $item): void {
            DB::afterCommit(fn () => app(AttachmentStore::class)->deleteDirectory($item));
        });
    }

    protected function casts(): array
    {
        return [
            'to_json' => 'array',
            'cc_json' => 'array',
            'received_at' => 'datetime',
            'status' => IntakeStatus::class,
            'imported_at' => 'datetime',
            'import_attempts' => 'integer',
            'next_import_at' => 'datetime',
            'processed_at' => 'datetime',
            'outlook_category_synced_at' => 'datetime',
        ];
    }

    /**
     * Visible in triage: completely imported, failed (also a failed import, R6) or finished
     * (a failed import that was discarded stays findable). An import in progress stays hidden.
     *
     * @param  Builder<IntakeItem>  $query
     */
    public function scopeVisibleInTriage(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNotNull('imported_at')
            ->orWhereIn('status', [
                IntakeStatus::Failed->value,
                ...array_map(fn (IntakeStatus $status): string => $status->value, IntakeStatus::finalCases()),
            ]));
    }

    /**
     * Open for the triage actions (create task, forward, discard): pending or failed, and no
     * ClickUp task yet. A failed item with clickup_task_id is a partial success (task created,
     * uploads missing, decisions round 1, point 5). Step 5 handles it with its own ability
     * ("Fehlende Anhänge erneut hochladen") and its own transition, not with these actions.
     * scopeProcessable() is the same rule as a query.
     */
    public function isProcessable(): bool
    {
        return $this->status->isProcessable() && $this->clickup_task_id === null;
    }

    /**
     * @param  Builder<IntakeItem>  $query
     */
    public function scopeProcessable(Builder $query): void
    {
        $query
            ->whereIn('status', array_map(
                fn (IntakeStatus $status): string => $status->value,
                array_filter(IntakeStatus::cases(), fn (IntakeStatus $status): bool => $status->isProcessable()),
            ))
            ->whereNull('clickup_task_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * @return HasMany<IntakeAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(IntakeAttachment::class);
    }
}
