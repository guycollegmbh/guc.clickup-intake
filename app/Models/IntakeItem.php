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
 *   Triage shows imported items only (scope imported()).
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
            'processed_at' => 'datetime',
            'outlook_category_synced_at' => 'datetime',
        ];
    }

    /**
     * Visible in triage: completely imported, including all attachments.
     *
     * @param  Builder<IntakeItem>  $query
     */
    public function scopeImported(Builder $query): void
    {
        $query->whereNotNull('imported_at');
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
