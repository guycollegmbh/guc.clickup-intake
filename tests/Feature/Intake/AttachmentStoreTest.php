<?php

use App\Intake\AttachmentStore;
use App\Models\IntakeAttachment;
use App\Models\IntakeItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(fn () => Storage::fake('local'));

/**
 * @return resource
 */
function attachmentStream(string $content)
{
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, $content);
    rewind($stream);

    return $stream;
}

/**
 * Delivers one chunk, then fails like a dropped connection.
 */
class BrokenStream
{
    /** @var resource|null */
    public $context;

    private int $reads = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        if ($this->reads++ === 0) {
            return str_repeat('a', 100);
        }

        throw new RuntimeException('connection reset');
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_stat(): array
    {
        return [];
    }
}

function attachmentMetadata(array $overrides = []): array
{
    return array_merge([
        'graph_attachment_id' => 'AAMk-'.Str::uuid(),
        'name' => '../../evil name.pdf',
        'content_type' => 'application/pdf',
        'size' => 11,
    ], $overrides);
}

it('writes the file under ids and sets storage_path', function () {
    $item = IntakeItem::factory()->create();

    $attachment = app(AttachmentStore::class)->store($item, attachmentMetadata(), attachmentStream('hello world'));

    expect($attachment->storage_path)->toBe("intake/{$item->id}/{$attachment->id}")
        ->and($attachment->error)->toBeNull()
        ->and($attachment->fresh()->storage_path)->toBe($attachment->storage_path);
    Storage::disk('local')->assertExists($attachment->storage_path);
    expect(Storage::disk('local')->get($attachment->storage_path))->toBe('hello world');
});

it('streams content larger than one chunk', function () {
    $item = IntakeItem::factory()->create();
    $content = random_bytes(3 * 1024 * 1024 + 17);

    $attachment = app(AttachmentStore::class)->store($item, attachmentMetadata(['size' => strlen($content)]), attachmentStream($content));

    expect(Storage::disk('local')->get($attachment->storage_path))->toBe($content);
});

it('marks the row and leaves no file when the stream breaks', function () {
    stream_wrapper_register('broken', BrokenStream::class);

    try {
        $item = IntakeItem::factory()->create();
        $attachment = app(AttachmentStore::class)->store($item, attachmentMetadata(), fopen('broken://x', 'rb'));
    } finally {
        stream_wrapper_unregister('broken');
    }

    expect($attachment->storage_path)->toBeNull()
        ->and($attachment->fresh()->error)->toBe('Der Anhang konnte nicht gespeichert werden.');
    expect(Storage::disk('local')->allFiles('intake'))->toBe([]);
});

it('marks the row when the file cannot be written', function () {
    if (function_exists('posix_getuid') && posix_getuid() === 0) {
        $this->markTestSkipped('root ignores file permissions');
    }

    $item = IntakeItem::factory()->create();
    Storage::disk('local')->makeDirectory('intake');
    $directory = Storage::disk('local')->path('intake');
    chmod($directory, 0555);

    try {
        $attachment = app(AttachmentStore::class)->store($item, attachmentMetadata(), attachmentStream('hello world'));
    } finally {
        chmod($directory, 0755);
    }

    expect($attachment->fresh()->error)->toBe('Der Anhang konnte nicht gespeichert werden.')
        ->and($attachment->fresh()->storage_path)->toBeNull();
    expect(Storage::disk('local')->allFiles('intake'))->toBe([]);
});

it('does not store an attachment declared too large', function () {
    config(['intake.attachment_max_mb' => 1]);
    $item = IntakeItem::factory()->create();

    $attachment = app(AttachmentStore::class)->store($item, attachmentMetadata(['size' => 1024 * 1024 + 1]), attachmentStream('x'));

    expect($attachment->fresh()->error)->toBe('Der Anhang ist grösser als 1 MB und wurde nicht gespeichert.')
        ->and($attachment->fresh()->storage_path)->toBeNull();
    expect(Storage::disk('local')->allFiles('intake'))->toBe([]);
});

it('stops and cleans up when the content exceeds the limit despite a small declared size', function () {
    config(['intake.attachment_max_mb' => 1]);
    $item = IntakeItem::factory()->create();

    $attachment = app(AttachmentStore::class)->store($item, attachmentMetadata(['size' => 10]), attachmentStream(str_repeat('x', 1024 * 1024 + 1)));

    expect($attachment->fresh()->error)->toContain('grösser als 1 MB')
        ->and($attachment->fresh()->storage_path)->toBeNull();
    expect(Storage::disk('local')->allFiles('intake'))->toBe([]);
});

it('accepts content of exactly the limit', function () {
    config(['intake.attachment_max_mb' => 1]);
    $item = IntakeItem::factory()->create();

    $attachment = app(AttachmentStore::class)->store($item, attachmentMetadata(['size' => 1024 * 1024]), attachmentStream(str_repeat('x', 1024 * 1024)));

    expect($attachment->error)->toBeNull();
    Storage::disk('local')->assertExists($attachment->storage_path);
});

it('rejects content that is not a stream', function () {
    app(AttachmentStore::class)->store(IntakeItem::factory()->create(), attachmentMetadata(), 'hello');
})->throws(InvalidArgumentException::class);

it('deletes directory and rows together with the item', function () {
    $item = IntakeItem::factory()->create();
    $other = IntakeItem::factory()->create();
    $store = app(AttachmentStore::class);
    $store->store($item, attachmentMetadata(), attachmentStream('a'));
    $store->store($item, attachmentMetadata(), attachmentStream('b'));
    $kept = $store->store($other, attachmentMetadata(), attachmentStream('c'));

    $item->delete();

    Storage::disk('local')->assertMissing("intake/{$item->id}");
    Storage::disk('local')->assertExists($kept->storage_path);
    expect(IntakeAttachment::query()->where('intake_item_id', $item->id)->count())->toBe(0)
        ->and(IntakeAttachment::query()->count())->toBe(1);
});

it('closes the source stream in every outcome', function (int $declaredSize, int $maxMb) {
    config(['intake.attachment_max_mb' => $maxMb]);
    $stream = attachmentStream('hello world');

    app(AttachmentStore::class)->store(IntakeItem::factory()->create(), attachmentMetadata(['size' => $declaredSize]), $stream);

    expect(is_resource($stream))->toBeFalse();
})->with([
    'stored' => [11, 50],
    'declared too large' => [2 * 1024 * 1024, 1],
]);

it('closes the source stream when the stream breaks', function () {
    stream_wrapper_register('broken', BrokenStream::class);

    try {
        $stream = fopen('broken://x', 'rb');
        app(AttachmentStore::class)->store(IntakeItem::factory()->create(), attachmentMetadata(), $stream);
    } finally {
        stream_wrapper_unregister('broken');
    }

    expect(is_resource($stream))->toBeFalse();
});

it('rejects a second row for the same Graph attachment and closes the stream', function () {
    $item = IntakeItem::factory()->create();
    $store = app(AttachmentStore::class);
    $store->store($item, attachmentMetadata(['graph_attachment_id' => 'AAMk-same']), attachmentStream('a'));
    $stream = attachmentStream('b');

    expect(fn () => $store->store($item, attachmentMetadata(['graph_attachment_id' => 'AAMk-same']), $stream))
        ->toThrow(QueryException::class);
    expect(is_resource($stream))->toBeFalse()
        ->and(IntakeAttachment::query()->count())->toBe(1);
});

it('allows the same Graph attachment id on two items', function () {
    $store = app(AttachmentStore::class);
    $store->store(IntakeItem::factory()->create(), attachmentMetadata(['graph_attachment_id' => 'AAMk-same']), attachmentStream('a'));
    $store->store(IntakeItem::factory()->create(), attachmentMetadata(['graph_attachment_id' => 'AAMk-same']), attachmentStream('b'));

    expect(IntakeAttachment::query()->count())->toBe(2);
});

it('marks the row and removes the file when saving storage_path fails', function () {
    IntakeAttachment::saving(function (IntakeAttachment $attachment): void {
        if ($attachment->storage_path !== null) {
            throw new RuntimeException('database gone');
        }
    });
    $item = IntakeItem::factory()->create();

    expect(fn () => app(AttachmentStore::class)->store($item, attachmentMetadata(), attachmentStream('hello world')))
        ->toThrow(RuntimeException::class, 'database gone');

    $attachment = IntakeAttachment::query()->sole();
    expect($attachment->storage_path)->toBeNull()
        ->and($attachment->error)->toBe('Der Anhang konnte nicht gespeichert werden.');
    expect(Storage::disk('local')->allFiles('intake'))->toBe([]);
});

it('keeps the files when the item delete is rolled back', function () {
    $item = IntakeItem::factory()->create();
    $attachment = app(AttachmentStore::class)->store($item, attachmentMetadata(), attachmentStream('a'));

    DB::beginTransaction();
    $item->delete();
    DB::rollBack();

    expect(IntakeItem::query()->find($item->id))->not->toBeNull();
    Storage::disk('local')->assertExists($attachment->storage_path);
});

it('deletes the files after the item delete is committed', function () {
    $item = IntakeItem::factory()->create();
    app(AttachmentStore::class)->store($item, attachmentMetadata(), attachmentStream('a'));

    DB::transaction(fn () => $item->delete());

    Storage::disk('local')->assertMissing("intake/{$item->id}");
});
