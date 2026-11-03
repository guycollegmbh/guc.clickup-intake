<?php

use App\Enums\AttachmentType;
use App\Intake\AttachmentStore;
use App\Models\IntakeAttachment;
use App\Models\IntakeItem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(fn () => Storage::fake('local'));

function storeAttachment(IntakeItem $item, string $content = 'content')
{
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, $content);
    rewind($stream);

    return app(AttachmentStore::class)->store($item, [
        'graph_attachment_id' => 'AAMk-'.Str::uuid(),
        'name' => 'file.pdf',
        'size' => strlen($content),
    ], $stream);
}

it('reports 0 when disk and database agree', function () {
    storeAttachment(IntakeItem::factory()->create());
    IntakeItem::factory()->create();

    $this->artisan('intake:orphans')
        ->expectsOutput('Orphans: 0')
        ->assertSuccessful();
});

it('reports 0 without any intake directory', function () {
    $this->artisan('intake:orphans')->expectsOutput('Orphans: 0')->assertSuccessful();
});

it('finds a directory without item', function () {
    Storage::disk('local')->put('intake/999/1', 'x');

    $this->artisan('intake:orphans')
        ->expectsOutput('Directory without item: intake/999')
        ->expectsOutput('Orphans: 1')
        ->assertFailed();
});

it('finds a row whose file is missing', function () {
    $attachment = storeAttachment(IntakeItem::factory()->create());
    Storage::disk('local')->delete($attachment->storage_path);

    $this->artisan('intake:orphans')
        ->expectsOutput("Attachment row without file: #{$attachment->id} {$attachment->storage_path}")
        ->assertFailed();
});

it('finds a file without attachment row', function () {
    $item = IntakeItem::factory()->create();
    Storage::disk('local')->put("intake/{$item->id}/12345", 'x');

    $this->artisan('intake:orphans')
        ->expectsOutput("File without attachment row: intake/{$item->id}/12345")
        ->assertFailed();
});

it('finds a file outside an item directory', function () {
    Storage::disk('local')->put('intake/stray', 'x');

    $this->artisan('intake:orphans')
        ->expectsOutput('File outside an item directory: intake/stray')
        ->assertFailed();
});

it('leaves a running import alone and reports it as incomplete when stale', function () {
    $attachment = IntakeAttachment::factory()->create();
    Storage::disk('local')->put(AttachmentStore::pathFor($attachment), 'partial');

    $this->artisan('intake:orphans')->expectsOutput('Orphans: 0')->assertSuccessful();

    $this->travel(AttachmentStore::STALE_AFTER_MINUTES + 1)->minutes();

    $this->artisan('intake:orphans')
        ->expectsOutput('Incomplete file: '.AttachmentStore::pathFor($attachment))
        ->expectsOutput("Incomplete attachment row: #{$attachment->id}")
        ->expectsOutput('Orphans: 2')
        ->assertFailed();
});

it('reports a stale row without path and without error', function () {
    $attachment = IntakeAttachment::factory()->create();

    $this->travel(AttachmentStore::STALE_AFTER_MINUTES + 1)->minutes();

    $this->artisan('intake:orphans')
        ->expectsOutput("Incomplete attachment row: #{$attachment->id}")
        ->assertFailed();
});

it('does not report rows with an error or reference attachments', function () {
    IntakeAttachment::factory()->create()->forceFill(['error' => 'Fehler'])->save();
    IntakeAttachment::factory()->create(['type' => AttachmentType::Reference]);

    $this->travel(AttachmentStore::STALE_AFTER_MINUTES + 1)->minutes();

    $this->artisan('intake:orphans')->expectsOutput('Orphans: 0')->assertSuccessful();
});

it('treats a file whose name is not a row id as without row', function () {
    $attachment = IntakeAttachment::factory()->create();
    Storage::disk('local')->put("intake/{$attachment->intake_item_id}/{$attachment->id}.part", 'x');

    $this->artisan('intake:orphans')
        ->expectsOutput("File without attachment row: intake/{$attachment->intake_item_id}/{$attachment->id}.part")
        ->assertFailed();
});
