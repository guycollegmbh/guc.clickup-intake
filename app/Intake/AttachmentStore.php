<?php

namespace App\Intake;

use App\Enums\AttachmentType;
use App\Models\IntakeAttachment;
use App\Models\IntakeItem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The only place that writes or removes attachment files (R8).
 *
 * Layout on disk "local": intake/{item_id}/{attachment_id}. Paths are built from ids only,
 * never from file names. Row first, then file, then storage_path. Content is copied in chunks
 * from the given stream and never held in memory as a whole (R5).
 */
class AttachmentStore
{
    public const DISK = 'local';

    public const ROOT = 'intake';

    /**
     * A row without storage_path and without error is an import in progress. After this many
     * minutes without change, intake:orphans reports it (and a partial file) as incomplete.
     */
    public const STALE_AFTER_MINUTES = 15;

    private const CHUNK_BYTES = 1024 * 1024;

    /**
     * Creates the attachment row and writes its content. On failure the row carries an error
     * and no (partial) file is left behind. The caller decides what that means for the item.
     *
     * The source stream is always closed when this method returns or throws, also when the
     * content is not read at all (too large). A second row for the same Graph attachment of the
     * item throws a QueryException (unique index), there is no silent duplicate.
     *
     * @param  array<string, mixed>  $attributes  fillable IntakeAttachment fields
     * @param  resource  $stream  readable stream with the attachment content, closed afterwards
     */
    public function store(IntakeItem $item, array $attributes, mixed $stream): IntakeAttachment
    {
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('Attachment content must be a stream resource.');
        }

        try {
            return $this->storeStream($item, $attributes, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  resource  $stream
     */
    private function storeStream(IntakeItem $item, array $attributes, $stream): IntakeAttachment
    {
        /** @var IntakeAttachment $attachment */
        $attachment = $item->attachments()->create($attributes);
        $maxBytes = $this->maxBytes();

        if ($attachment->size > $maxBytes) {
            return $this->markFailed($attachment, $this->tooLargeMessage());
        }

        $path = self::pathFor($attachment);

        try {
            $complete = $this->copy($stream, $path, $maxBytes);
        } catch (Throwable $e) {
            $this->removeFile($path);
            Log::warning('Intake attachment could not be written.', [
                'attachment_id' => $attachment->id,
                'exception' => $e::class,
            ]);

            return $this->markFailed($attachment, 'Der Anhang konnte nicht gespeichert werden.');
        }

        if (! $complete) {
            $this->removeFile($path);

            return $this->markFailed($attachment, $this->tooLargeMessage());
        }

        try {
            $attachment->storage_path = $path;
            $attachment->save();
        } catch (Throwable $e) {
            $this->removeFile($path);

            // Leave no row without storage_path and without error behind, if the database allows.
            try {
                $this->markFailed($attachment, 'Der Anhang konnte nicht gespeichert werden.');
            } catch (Throwable) {
                // intake:orphans reports the row as incomplete.
            }

            throw $e;
        }

        return $attachment;
    }

    /**
     * Called by the IntakeItem deleted event. A failure is logged and left to intake:orphans.
     */
    public function deleteDirectory(IntakeItem $item): void
    {
        $directory = self::directoryFor($item->getKey());

        if (! $this->disk()->deleteDirectory($directory) && $this->disk()->exists($directory)) {
            Log::warning('Intake item directory could not be deleted.', ['intake_item_id' => $item->getKey()]);
        }
    }

    /**
     * Inconsistencies between disk and database. Empty when everything is in order.
     * Rows and files of imports younger than STALE_AFTER_MINUTES are left alone.
     *
     * @return list<string>
     */
    public function orphans(): array
    {
        $disk = $this->disk();
        $found = [];
        $staleBefore = now()->subMinutes(self::STALE_AFTER_MINUTES);

        foreach ($disk->files(self::ROOT) as $file) {
            $found[] = "File outside an item directory: {$file}";
        }

        foreach ($disk->directories(self::ROOT) as $directory) {
            $id = basename($directory);

            if (! ctype_digit($id) || ! IntakeItem::query()->whereKey((int) $id)->exists()) {
                $found[] = "Directory without item: {$directory}";

                continue;
            }

            $rows = IntakeAttachment::query()
                ->where('intake_item_id', (int) $id)
                ->get(['id', 'storage_path', 'updated_at'])
                ->keyBy('id');
            $known = $rows->whereNotNull('storage_path')->pluck('storage_path')->flip();

            foreach ($disk->allFiles($directory) as $file) {
                if ($known->has($file)) {
                    continue;
                }

                // A partial file of a row still without storage_path is incomplete, not orphaned.
                $name = basename($file);
                $row = $file === $directory.'/'.$name && ctype_digit($name) ? $rows->get((int) $name) : null;

                if ($row === null || $row->storage_path !== null) {
                    $found[] = "File without attachment row: {$file}";
                } elseif ($row->updated_at < $staleBefore) {
                    $found[] = "Incomplete file: {$file}";
                }
            }
        }

        IntakeAttachment::query()
            ->whereNull('storage_path')
            ->whereNull('error')
            ->where('type', '!=', AttachmentType::Reference->value)
            ->where('updated_at', '<', $staleBefore)
            ->lazyById()
            ->each(function (IntakeAttachment $attachment) use (&$found): void {
                $found[] = "Incomplete attachment row: #{$attachment->id}";
            });

        IntakeAttachment::query()
            ->whereNotNull('storage_path')
            ->lazyById()
            ->each(function (IntakeAttachment $attachment) use ($disk, &$found): void {
                if (! $disk->exists($attachment->storage_path)) {
                    $found[] = "Attachment row without file: #{$attachment->id} {$attachment->storage_path}";
                }
            });

        return $found;
    }

    public static function directoryFor(int $itemId): string
    {
        return self::ROOT.'/'.$itemId;
    }

    public static function pathFor(IntakeAttachment $attachment): string
    {
        return self::directoryFor($attachment->intake_item_id).'/'.$attachment->getKey();
    }

    /**
     * @param  resource  $source
     * @return bool false if the content exceeds the limit
     */
    private function copy($source, string $path, int $maxBytes): bool
    {
        if (! $this->disk()->makeDirectory(dirname($path))) {
            throw new RuntimeException('Cannot create the attachment directory.');
        }

        // "x" refuses to overwrite: an existing file would mean a broken invariant.
        $target = @fopen($this->disk()->path($path), 'xb');

        if ($target === false) {
            throw new RuntimeException('Cannot open the attachment file for writing.');
        }

        try {
            $bytes = 0;

            while (! feof($source)) {
                $chunk = fread($source, self::CHUNK_BYTES);

                if ($chunk === false) {
                    throw new RuntimeException('Reading the attachment stream failed.');
                }

                $bytes += strlen($chunk);

                if ($bytes > $maxBytes) {
                    return false;
                }

                if (fwrite($target, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Writing the attachment file failed.');
                }
            }

            if (! fflush($target)) {
                throw new RuntimeException('Writing the attachment file failed.');
            }
        } finally {
            fclose($target);
        }

        return true;
    }

    private function markFailed(IntakeAttachment $attachment, string $error): IntakeAttachment
    {
        $attachment->storage_path = null;
        $attachment->error = $error;
        $attachment->save();

        return $attachment;
    }

    private function removeFile(string $path): void
    {
        if ($this->disk()->exists($path) && ! $this->disk()->delete($path)) {
            Log::warning('Partial intake attachment file could not be removed.', ['path' => $path]);
        }
    }

    private function maxBytes(): int
    {
        return (int) config('intake.attachment_max_mb') * 1024 * 1024;
    }

    private function tooLargeMessage(): string
    {
        $mb = (int) config('intake.attachment_max_mb');

        return "Der Anhang ist grösser als {$mb} MB und wurde nicht gespeichert.";
    }

    private function disk(): FilesystemAdapter
    {
        return Storage::disk(self::DISK);
    }
}
