<?php

use App\Enums\AttachmentType;
use App\Enums\IntakeStatus;
use App\Intake\AttachmentStore;
use App\Intake\ItemTransitions;
use App\Intake\MessageImporter;
use App\Microsoft\UserTokens;
use App\Models\IntakeAttachment;
use App\Models\IntakeItem;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\FakeGraph;

beforeEach(function () {
    Storage::fake('local');
    Sleep::fake();
});

/**
 * @param  array<string, mixed>  $summary
 */
function importSummary(User $user, array $summary, ?CarbonInterface $listedAt = null): IntakeItem
{
    return app(MessageImporter::class)->import($user, app(UserTokens::class)->graphFor($user), $summary, $listedAt);
}

function storedContent(IntakeAttachment $attachment): string
{
    return Storage::disk('local')->get($attachment->storage_path);
}

/**
 * @return resource
 */
function attachmentContent(string $content)
{
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, $content);
    rewind($stream);

    return $stream;
}

it('imports a new mail with recipients, HTML body and derived text', function () {
    $user = User::factory()->connected()->create(['mailbox_upn' => 'anna@guycolle.test']);
    FakeGraph::fake(FakeGraph::messageRoutes(FakeGraph::message('msg-1')));

    $item = importSummary($user, FakeGraph::summary('msg-1'));

    expect($item->is(IntakeItem::sole()))->toBeTrue();
    $item->refresh();
    expect($item->user_id)->toBe($user->id)
        ->and($item->mailbox_upn)->toBe('anna@guycolle.test')
        ->and($item->internet_message_id)->toBe('<msg-1@mail.example.test>')
        ->and($item->graph_message_id)->toBe('msg-1')
        ->and($item->subject)->toBe('Anfrage msg-1')
        ->and($item->from_email)->toBe('erika@example.test')
        ->and($item->to_json)->toBe([['name' => 'Team', 'address' => 'team@guycolle.test']])
        ->and($item->cc_json)->toBe([['name' => 'Max Muster', 'address' => 'max@example.test']])
        ->and($item->received_at->toIso8601String())->toBe('2026-11-01T08:00:00+00:00')
        ->and($item->body_html)->toContain('<p>Guten Tag</p>')
        ->and($item->body_text)->toBe("Guten Tag\nBitte prüfen.\nDanke")
        ->and($item->status)->toBe(IntakeStatus::Pending)
        ->and($item->imported_at)->not->toBeNull()
        ->and($item->import_attempts)->toBe(0)
        ->and($item->error)->toBeNull();

    Http::assertSent(fn (Request $r): bool => FakeGraph::path($r) === 'me/messages/msg-1?$select=id,internetMessageId,subject,from,toRecipients,ccRecipients,receivedDateTime,webLink,body'
        && $r->header('Prefer') === ['IdType="ImmutableId", outlook.body-content-type="html"']);
    Http::assertSent(fn (Request $r): bool => FakeGraph::path($r) === 'me/messages/msg-1/attachments?$select='.MessageImporter::ATTACHMENT_SELECT);
});

it('asks for contentId in the form Graph accepts (verified in the tenant)', function () {
    expect(MessageImporter::ATTACHMENT_SELECT)->toContain('microsoft.graph.fileAttachment/contentId')
        ->and(explode(',', MessageImporter::ATTACHMENT_SELECT))->not->toContain('contentId');
});

it('keeps a plain text body as text and escapes it for the HTML view', function () {
    $user = User::factory()->connected()->create();
    FakeGraph::fake(FakeGraph::messageRoutes(FakeGraph::message('msg-1', [
        'body' => ['contentType' => 'text', 'content' => "Hallo <b>\nZeile 2"],
    ])));

    importSummary($user, FakeGraph::summary('msg-1'));

    $item = IntakeItem::sole();
    expect($item->body_text)->toBe("Hallo <b>\nZeile 2")
        ->and($item->body_html)->toBe("Hallo &lt;b&gt;<br />\nZeile 2");
});

it('derives text without style, script and entities', function () {
    expect(MessageImporter::htmlToText('<style>.a{}</style><script>x()</script><div>A&nbsp;&amp;&nbsp;B</div><ul><li>Eins</li><li>Zwei</li></ul>'))
        ->toBe("A & B\nEins\nZwei");
});

it('streams file attachments to disk and preselects only regular ones', function () {
    $user = User::factory()->connected()->create();
    $message = FakeGraph::message('msg-1', ['body' => ['contentType' => 'html', 'content' => '<p>Logo <img src="cid:logo@x"></p>']]);
    FakeGraph::fake(FakeGraph::messageRoutes($message, [
        FakeGraph::fileAttachment('att-1', 'offerte.pdf', 12),
        FakeGraph::fileAttachment('att-2', 'image001.png', 9, ['contentType' => 'image/png', 'isInline' => true, 'contentId' => 'image001@x']),
        FakeGraph::fileAttachment('att-3', 'logo.png', 4, ['contentType' => 'image/png', 'isInline' => false, 'contentId' => '<logo@x>']),
    ], [
        'att-1' => 'pdf-content!',
        'att-2' => 'png-bytes',
        'att-3' => 'logo',
    ]));

    importSummary($user, FakeGraph::summary('msg-1'));

    [$pdf, $inline, $cidInline] = IntakeItem::sole()->attachments()->orderBy('id')->get()->all();
    expect($pdf->type)->toBe(AttachmentType::File)
        ->and($pdf->is_inline)->toBeFalse()
        ->and($pdf->selected_for_upload)->toBeTrue()
        ->and(storedContent($pdf))->toBe('pdf-content!')
        ->and($inline->is_inline)->toBeTrue()
        ->and($inline->content_id)->toBe('image001@x')
        ->and($inline->selected_for_upload)->toBeFalse()
        ->and(storedContent($inline))->toBe('png-bytes')
        ->and($cidInline->is_inline)->toBeTrue()
        ->and($cidInline->content_id)->toBe('logo@x')
        ->and($cidInline->selected_for_upload)->toBeFalse();

    expect(app(AttachmentStore::class)->orphans())->toBe([]);
});

it('normalises hostile names and determines the content type from the bytes', function () {
    $user = User::factory()->connected()->create();
    FakeGraph::fake(FakeGraph::messageRoutes(
        FakeGraph::message('msg-1'),
        [FakeGraph::fileAttachment('att-1', "a\u{202E}fdp.exe/../x", 30, ['contentType' => 'text/html'])],
        ['att-1' => "%PDF-1.4\n%âãÏÓ\n1 0 obj\n<<>>\nendobj\n"],
    ));

    importSummary($user, FakeGraph::summary('msg-1'));

    $attachment = IntakeAttachment::sole();
    expect($attachment->name)->toBe('afdp.exe_.._x')
        ->and($attachment->content_type)->toBe('application/pdf')
        ->and($attachment->size)->toBe(strlen("%PDF-1.4\n%âãÏÓ\n1 0 obj\n<<>>\nendobj\n"));
});

it('falls back to a neutral name', function (string $name) {
    expect(MessageImporter::safeName($name))->toBe('Anhang');
})->with(['', "\u{202E}\u{2066}", "\x00\x1F", "\xFF\xFE"]);

it('stores an attached mail as .eml', function () {
    $user = User::factory()->connected()->create();
    FakeGraph::fake(FakeGraph::messageRoutes(
        FakeGraph::message('msg-1'),
        [FakeGraph::itemAttachment('att-1', 'Weitergeleitete Mail')],
        ['att-1' => "Subject: Test\r\n\r\nInhalt"],
    ));

    importSummary($user, FakeGraph::summary('msg-1'));

    $attachment = IntakeAttachment::sole();
    expect($attachment->type)->toBe(AttachmentType::Item)
        ->and($attachment->name)->toBe('Weitergeleitete Mail.eml')
        ->and($attachment->content_type)->toBe('message/rfc822')
        ->and($attachment->selected_for_upload)->toBeTrue()
        ->and(storedContent($attachment))->toBe("Subject: Test\r\n\r\nInhalt");
});

it('records a cloud link as a row without file and never downloads it', function () {
    $user = User::factory()->connected()->create();
    FakeGraph::fake(FakeGraph::messageRoutes(FakeGraph::message('msg-1'), [FakeGraph::referenceAttachment('att-1')]));

    importSummary($user, FakeGraph::summary('msg-1'));

    $attachment = IntakeAttachment::sole();
    expect($attachment->type)->toBe(AttachmentType::Reference)
        ->and($attachment->name)->toBe('Projektplan.xlsx')
        ->and($attachment->storage_path)->toBeNull()
        ->and($attachment->error)->toBeNull()
        ->and($attachment->selected_for_upload)->toBeFalse()
        ->and(IntakeItem::sole()->status)->toBe(IntakeStatus::Pending);
    Http::assertNotSent(fn (Request $r): bool => str_ends_with(FakeGraph::path($r), '$value'));
    expect(app(AttachmentStore::class)->orphans())->toBe([]);
});

it('does not download an attachment above the size limit and still imports the mail', function () {
    config(['intake.attachment_max_mb' => 1]);
    $user = User::factory()->connected()->create();
    FakeGraph::fake(FakeGraph::messageRoutes(FakeGraph::message('msg-1'), [
        FakeGraph::fileAttachment('att-1', 'video.mp4', 2 * 1024 * 1024),
    ]));

    importSummary($user, FakeGraph::summary('msg-1'));

    $attachment = IntakeAttachment::sole();
    expect($attachment->error)->toBe('Der Anhang ist grösser als 1 MB und wurde nicht gespeichert.')
        ->and($attachment->storage_path)->toBeNull()
        ->and($attachment->selected_for_upload)->toBeFalse()
        ->and(IntakeItem::sole()->status)->toBe(IntakeStatus::Pending)
        ->and(IntakeItem::sole()->imported_at)->not->toBeNull();
    Http::assertNotSent(fn (Request $r): bool => str_ends_with(FakeGraph::path($r), '$value'));
});

it('stops downloading once the attachments of one mail reach the limit', function () {
    config(['intake.attachment_max_mb' => 1, 'intake.item_max_mb' => 1]);
    $user = User::factory()->connected()->create();
    $half = str_repeat('a', 600 * 1024);
    FakeGraph::fake(FakeGraph::messageRoutes(FakeGraph::message('msg-1'), [
        FakeGraph::fileAttachment('att-1', 'teil1.bin', strlen($half)),
        FakeGraph::fileAttachment('att-2', 'teil2.bin', strlen($half)),
    ], ['att-1' => $half, 'att-2' => $half]));

    importSummary($user, FakeGraph::summary('msg-1'));

    [$first, $second] = IntakeAttachment::query()->orderBy('id')->get()->all();
    expect($first->storage_path)->not->toBeNull()
        ->and($second->storage_path)->toBeNull()
        ->and($second->error)->toBe('Die Anhänge dieser Mail sind zusammen grösser als 1 MB. Dieser Anhang wurde nicht gespeichert.')
        ->and(IntakeItem::sole()->imported_at)->not->toBeNull();
    Http::assertNotSent(fn (Request $r): bool => str_contains(FakeGraph::path($r), 'att-2/$value'));
});

it('marks a failed attachment download as failed import and waits before the next try', function () {
    $user = User::factory()->connected()->create();
    FakeGraph::fake(FakeGraph::messageRoutes(
        FakeGraph::message('msg-1'),
        [FakeGraph::fileAttachment('att-1', 'ok.pdf'), FakeGraph::fileAttachment('att-2', 'kaputt.pdf')],
        ['att-1' => 'fine', 'att-2' => Http::response(['error' => ['code' => 'InternalServerError']], 500)],
    ));

    importSummary($user, FakeGraph::summary('msg-1'));

    $item = IntakeItem::sole();
    expect($item->status)->toBe(IntakeStatus::Failed)
        ->and($item->error)->toBe('Import fehlgeschlagen: Microsoft 365 hat mit Fehler 500 geantwortet.')
        ->and($item->imported_at)->toBeNull()
        ->and($item->import_attempts)->toBe(1)
        ->and($item->next_import_at->diffInMinutes(now(), true))->toEqualWithDelta(2, 0.1)
        ->and(IntakeItem::query()->visibleInTriage()->count())->toBe(1);
});

it('fails the item when the attachment stream breaks', function () {
    $user = User::factory()->connected()->create();
    FakeGraph::fake(FakeGraph::messageRoutes(
        FakeGraph::message('msg-1'),
        [FakeGraph::fileAttachment('att-1', 'kaputt.pdf')],
        ['att-1' => 'ok'],
    ));
    app()->instance(AttachmentStore::class, new class extends AttachmentStore
    {
        public function store(IntakeItem $item, array $attributes, mixed $stream): IntakeAttachment
        {
            fclose($stream);
            $attachment = $item->attachments()->create($attributes);
            $attachment->forceFill(['error' => 'Der Anhang konnte nicht gespeichert werden.'])->save();

            return $attachment;
        }
    });

    importSummary($user, FakeGraph::summary('msg-1'));

    expect(IntakeItem::sole()->error)->toBe('Import fehlgeschlagen: Der Anhang «kaputt.pdf» konnte nicht geladen werden.');
});

it('creates a failed item when the mail cannot be read at all', function () {
    $user = User::factory()->connected()->create();
    FakeGraph::fake(['GET me/messages/msg-1?*' => Http::response(['error' => ['code' => 'ErrorItemNotFound']], 404)]);

    importSummary($user, FakeGraph::summary('msg-1'));

    $item = IntakeItem::sole();
    expect($item->status)->toBe(IntakeStatus::Failed)
        ->and($item->subject)->toBe('Anfrage msg-1')
        ->and($item->error)->toBe('Import fehlgeschlagen: Die Mail oder ein Anhang wurde in Outlook nicht mehr gefunden.');
});

it('uses graph:{id} as key when the Message-ID is missing', function () {
    $user = User::factory()->connected()->create();
    FakeGraph::fake(FakeGraph::messageRoutes(FakeGraph::message('msg-1', ['internetMessageId' => null])));

    importSummary($user, FakeGraph::summary('msg-1', ['internetMessageId' => null]));
    importSummary($user, FakeGraph::summary('msg-1', ['internetMessageId' => null]));

    expect(IntakeItem::sole()->internet_message_id)->toBe('graph:msg-1');
});

it('imports a different mail with the same Message-ID as a separate item', function () {
    $user = User::factory()->connected()->create();
    $first = IntakeItem::factory()->for($user)->create([
        'graph_message_id' => 'msg-1',
        'internet_message_id' => '<same@mail.example.test>',
        'from_email' => 'erika@example.test',
        'received_at' => '2026-11-01T08:00:00Z',
    ]);
    $other = FakeGraph::summary('msg-2', [
        'internetMessageId' => '<same@mail.example.test>',
        'from' => ['emailAddress' => ['name' => 'Fremd', 'address' => 'fremd@example.test']],
    ]);
    FakeGraph::fake(FakeGraph::messageRoutes(FakeGraph::message('msg-2', $other)));

    $second = importSummary($user, $other);

    expect($second->id)->not->toBe($first->id)
        ->and($second->internet_message_id)->toBe('<same@mail.example.test>#msg-2')
        ->and($second->from_email)->toBe('fremd@example.test');

    // The next poll finds the separate item, nothing is imported twice.
    expect(importSummary($user, $other)->id)->toBe($second->id)
        ->and(IntakeItem::count())->toBe(2);
});

it('keeps items of different users apart', function () {
    $other = User::factory()->connected()->create();
    IntakeItem::factory()->for($other)->create(['internet_message_id' => '<msg-1@mail.example.test>']);
    $user = User::factory()->connected()->create();
    FakeGraph::fake(FakeGraph::messageRoutes(FakeGraph::message('msg-1')));

    importSummary($user, FakeGraph::summary('msg-1'));

    expect(IntakeItem::count())->toBe(2);
});

describe('dedupe table (E7, E8)', function () {
    function existingItem(User $user, array $attributes = []): IntakeItem
    {
        return IntakeItem::factory()->for($user)->create([
            'graph_message_id' => 'msg-1',
            'internet_message_id' => '<msg-1@mail.example.test>',
            'from_email' => 'erika@example.test',
            'received_at' => '2026-11-01T08:00:00Z',
            ...$attributes,
        ]);
    }

    it('re-imports an aborted import cleanly without duplicate rows or files', function () {
        $user = User::factory()->connected()->create();
        $item = existingItem($user, ['imported_at' => null, 'subject' => 'alt']);
        $old = app(AttachmentStore::class)->store($item, [
            'graph_attachment_id' => 'att-1',
            'name' => 'offerte.pdf',
            'size' => 3,
        ], attachmentContent('alt'));
        $item->attachments()->create(['graph_attachment_id' => 'att-2', 'name' => 'halb.pdf', 'size' => 3]);
        FakeGraph::fake(FakeGraph::messageRoutes(
            FakeGraph::message('msg-1'),
            [FakeGraph::fileAttachment('att-1', 'offerte.pdf', 3), FakeGraph::fileAttachment('att-2', 'halb.pdf', 3)],
            ['att-1' => 'neu', 'att-2' => 'ok!'],
        ));

        importSummary($user, FakeGraph::summary('msg-1'));

        $item->refresh();
        $attachments = $item->attachments()->orderBy('id')->get();
        expect($item->imported_at)->not->toBeNull()
            ->and($item->subject)->toBe('Anfrage msg-1')
            ->and($item->status)->toBe(IntakeStatus::Pending)
            ->and($attachments)->toHaveCount(2)
            ->and($attachments->pluck('id'))->not->toContain($old->id)
            ->and(storedContent($attachments[0]))->toBe('neu')
            ->and(Storage::disk('local')->exists(AttachmentStore::pathFor($old)))->toBeFalse()
            ->and(app(AttachmentStore::class)->orphans())->toBe([]);
    });

    it('repeats a failed import once the wait is over', function () {
        $user = User::factory()->connected()->create();
        $item = existingItem($user, [
            'status' => IntakeStatus::Failed,
            'error' => 'Import fehlgeschlagen.',
            'imported_at' => null,
            'import_attempts' => 2,
            'next_import_at' => now()->subMinute(),
        ]);
        FakeGraph::fake(FakeGraph::messageRoutes(FakeGraph::message('msg-1'), [FakeGraph::fileAttachment('att-1')], ['att-1' => 'x']));

        importSummary($user, FakeGraph::summary('msg-1'));

        $item->refresh();
        expect($item->status)->toBe(IntakeStatus::Pending)
            ->and($item->error)->toBeNull()
            ->and($item->imported_at)->not->toBeNull()
            ->and($item->import_attempts)->toBe(0)
            ->and($item->next_import_at)->toBeNull()
            ->and($item->attachments()->count())->toBe(1);
    });

    it('waits longer after each failed attempt, at most an hour', function () {
        $user = User::factory()->connected()->create();
        $item = existingItem($user, [
            'status' => IntakeStatus::Failed,
            'imported_at' => null,
            'import_attempts' => 2,
            'next_import_at' => now()->subMinute(),
        ]);
        FakeGraph::fake(['GET me/messages/msg-1?*' => Http::response([], 500)]);

        importSummary($user, FakeGraph::summary('msg-1'));

        $item->refresh();
        expect($item->import_attempts)->toBe(3)
            ->and($item->next_import_at->diffInMinutes(now(), true))->toEqualWithDelta(8, 0.1);

        expect(array_map(fn (int $n): int => ItemTransitions::retryDelayMinutes($n), [1, 2, 3, 4, 5, 6, 7, 20]))
            ->toBe([2, 4, 8, 16, 32, 60, 60, 60]);
    });

    it('does not repeat a failed import before the wait is over', function () {
        $user = User::factory()->connected()->create();
        existingItem($user, [
            'status' => IntakeStatus::Failed,
            'imported_at' => null,
            'next_import_at' => now()->addMinute(),
        ]);
        Http::fake();

        importSummary($user, FakeGraph::summary('msg-1'));

        Http::assertNothingSent();
    });

    it('does not re-import an item discarded while its import had failed', function () {
        $user = User::factory()->connected()->create();
        $item = existingItem($user, [
            'status' => IntakeStatus::Failed,
            'imported_at' => null,
            'next_import_at' => now()->subMinute(),
        ]);
        // Discarded in triage while the poll was running.
        IntakeItem::query()->whereKey($item->id)->update([
            'status' => IntakeStatus::Discarded->value,
            'processed_at' => now(),
            'outlook_category_synced_at' => now(),
        ]);
        Http::fake();

        importSummary($user, FakeGraph::summary('msg-1'), now()->subMinute());

        Http::assertNothingSent();
        expect($item->fresh()->status)->toBe(IntakeStatus::Discarded);
    });

    it('brings a discarded item with synced category back to pending', function () {
        $user = User::factory()->connected()->create();
        $item = existingItem($user, [
            'status' => IntakeStatus::Discarded,
            'processed_at' => now()->subDay(),
            'outlook_category_synced_at' => now()->subDay(),
        ]);
        Http::fake();

        importSummary($user, FakeGraph::summary('msg-1'), now());

        $item->refresh();
        expect($item->status)->toBe(IntakeStatus::Pending)
            ->and($item->processed_at)->toBeNull()
            ->and($item->outlook_category_synced_at)->toBeNull();
        Http::assertNothingSent();
    });

    it('does not reopen a discarded item synced after the mail list was read', function () {
        $user = User::factory()->connected()->create();
        $item = existingItem($user, [
            'status' => IntakeStatus::Discarded,
            'processed_at' => now(),
            'outlook_category_synced_at' => now(),
        ]);
        Http::fake();

        importSummary($user, FakeGraph::summary('msg-1'), now()->subMinute());

        expect($item->fresh()->status)->toBe(IntakeStatus::Discarded);
    });

    it('sets the category again for a final item without synced category', function (IntakeStatus $status, ?string $expected) {
        $user = User::factory()->connected()->create();
        $item = existingItem($user, ['status' => $status, 'processed_at' => now()]);
        FakeGraph::fake([
            'GET me/messages/msg-1?*' => Http::response(['categories' => ['ClickUp']]),
            'PATCH me/messages/msg-1' => fn (Request $r) => Http::response(['categories' => $r['categories']]),
        ]);

        importSummary($user, FakeGraph::summary('msg-1'));

        Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH' && $r['categories'] === ($expected === null ? [] : [$expected]));
        expect($item->fresh()->outlook_category_synced_at)->not->toBeNull()
            ->and($item->fresh()->status)->toBe($status);
    })->with([
        'discarded' => [IntakeStatus::Discarded, null],
        'created' => [IntakeStatus::Created, 'ClickUp ✓'],
        'forwarded' => [IntakeStatus::Forwarded, 'Support ✓'],
    ]);

    it('gives a copy of a finished mail its category and keeps the item', function () {
        $user = User::factory()->connected()->create();
        $item = existingItem($user, [
            'status' => IntakeStatus::Created,
            'processed_at' => now()->subDay(),
            'outlook_category_synced_at' => now()->subDay(),
        ]);
        FakeGraph::fake([
            'GET me/messages/msg-copy?*' => Http::response(['categories' => ['ClickUp']]),
            'PATCH me/messages/msg-copy' => fn (Request $r) => Http::response(['categories' => $r['categories']]),
        ]);

        // Same Message-ID, received time and sender, other Graph id: a copy, no new item.
        importSummary($user, FakeGraph::summary('msg-copy', ['internetMessageId' => '<msg-1@mail.example.test>']), now());
        importSummary($user, FakeGraph::summary('msg-1'), now());

        Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH' && FakeGraph::path($r) === 'me/messages/msg-copy'
            && $r['categories'] === ['ClickUp ✓']);
        $item->refresh();
        expect(IntakeItem::count())->toBe(1)
            ->and($item->graph_message_id)->toBe('msg-1')
            ->and($item->status)->toBe(IntakeStatus::Created);
    });

    it('does not reopen a discarded item because of a copy', function () {
        $user = User::factory()->connected()->create();
        $item = existingItem($user, [
            'status' => IntakeStatus::Discarded,
            'processed_at' => now()->subDay(),
            'outlook_category_synced_at' => now()->subDay(),
        ]);
        FakeGraph::fake([
            'GET me/messages/msg-copy?*' => Http::response(['categories' => ['ClickUp', 'Rot']]),
            'PATCH me/messages/msg-copy' => fn (Request $r) => Http::response(['categories' => $r['categories']]),
        ]);

        importSummary($user, FakeGraph::summary('msg-copy', ['internetMessageId' => '<msg-1@mail.example.test>']), now());

        Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH' && $r['categories'] === ['Rot']);
        expect($item->fresh()->status)->toBe(IntakeStatus::Discarded);
    });

    it('leaves the item alone otherwise', function (array $attributes) {
        $user = User::factory()->connected()->create();
        $item = existingItem($user, $attributes);
        $before = $item->fresh()->toArray();
        Http::fake();

        importSummary($user, FakeGraph::summary('msg-1'), now());

        Http::assertNothingSent();
        expect($item->fresh()->toArray())->toBe($before);
    })->with([
        'pending' => [[]],
        'creating' => [['status' => IntakeStatus::Creating]],
        'failed in a later step, without task' => [['status' => IntakeStatus::Failed, 'error' => 'Weiterleiten fehlgeschlagen.']],
        'failed in a later step, with task' => [['status' => IntakeStatus::Failed, 'clickup_task_id' => 'abc', 'error' => 'Upload fehlgeschlagen.']],
        'created and synced' => [['status' => IntakeStatus::Created, 'processed_at' => now()->subDay(), 'outlook_category_synced_at' => now()->subDay()]],
        'forwarded and synced' => [['status' => IntakeStatus::Forwarded, 'processed_at' => now()->subDay(), 'outlook_category_synced_at' => now()->subDay()]],
    ]);
});
