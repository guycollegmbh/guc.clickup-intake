<?php

namespace App\Models;

use App\Enums\AttachmentType;
use Database\Factories\IntakeAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadata of one Graph attachment. The file itself is written by AttachmentStore (R8),
 * which alone sets storage_path and error (storage errors only). selected_for_upload,
 * uploaded_to_clickup and upload_error belong to triage and the ClickUp upload (steps 4 and 5).
 *
 * A referenceAttachment (cloud link) gets a row without file: name only, triage shows
 * "Link in der Original-Mail" (E7).
 */
#[Fillable([
    'type',
    'graph_attachment_id',
    'name',
    'content_type',
    'size',
    'content_id',
    'is_inline',
])]
class IntakeAttachment extends Model
{
    /** @use HasFactory<IntakeAttachmentFactory> */
    use HasFactory;

    protected $attributes = [
        'type' => 'file',
    ];

    protected function casts(): array
    {
        return [
            'type' => AttachmentType::class,
            'size' => 'integer',
            'is_inline' => 'boolean',
            'selected_for_upload' => 'boolean',
            'uploaded_to_clickup' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<IntakeItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(IntakeItem::class, 'intake_item_id');
    }
}
