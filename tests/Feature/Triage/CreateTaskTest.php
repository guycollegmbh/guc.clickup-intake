<?php

use App\Enums\IntakeStatus;
use App\Filament\Resources\IntakeItems\Pages\TriageIntakeItem;
use App\Intake\AttachmentStore;
use App\Intake\ItemTransitions;
use App\Intake\TaskUploads;
use App\Intake\TriageActions;
use App\Intake\TriageResult;
use App\Jobs\UploadTaskAttachments;
use App\Models\IntakeAttachment;
use App\Models\IntakeItem;
use App\Models\IntakeRule;
use App\Models\Setting;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\FakeClickUp;
use Tests\Support\FakeGraph;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Sleep::fake();
    Storage::fake('local');
    configureClickUpApp();
});

/**
 * An imported item of a Microsoft-connected owner whose ClickUp member id is 501.
 *
 * @param  array<string, mixed>  $attributes
 */
function taskItem(?User $owner = null, array $attributes = []): IntakeItem
{
    // fresh(): all columns loaded, as for a signed-in user (strict models).
    $owner ??= User::factory()->connected()->clickUpConnected('owner-token', FakeClickUp::MEMBER_ID)->create(['name' => 'Mia Member'])->fresh();

    return IntakeItem::factory()->for($owner)->create([
        'graph_message_id' => 'msg-1',
        'subject' => 'AW: Offerte Website',
        'from_name' => 'Erika Beispiel',
        'from_email' => 'erika@kunde.test',
        'received_at' => '2026-11-01 08:00:00',
        'web_link' => 'https://outlook.office365.com/owa/?ItemID=msg-1',
        'body_html' => '<p>Guten Tag</p>',
        ...$attributes,
    ]);
}

function taskAttachment(IntakeItem $item, string $name, bool $selected = true): IntakeAttachment
{
    $attachment = IntakeAttachment::factory()->for($item, 'item')->create(['name' => $name, 'size' => strlen("Inhalt von {$name}")]);
    $path = AttachmentStore::pathFor($attachment);
    Storage::disk('local')->put($path, "Inhalt von {$name}");
    $attachment->forceFill(['storage_path' => $path, 'selected_for_upload' => $selected])->save();

    return $attachment;
}

/**
 * ClickUp and Graph routes for a successful task with uploads and comment.
 *
 * @param  array<string, mixed>  $overrides
 */
function fakeTaskRoutes(array $overrides = []): void
{
    // array_merge: an override replaces the default route in place.
    FakeClickUp::fake(array_merge([
        'POST list/*/task' => Http::response(FakeClickUp::task()),
        'POST task/abc123/attachment' => Http::response(['id' => 'att']),
        'POST task/abc123/comment' => Http::response(['id' => 'c1']),
        ...FakeClickUp::workspaceRoutes(),
    ], $overrides));
    FakeGraph::fake([
        'GET me/messages/msg-1?*' => Http::response(['categories' => ['ClickUp']]),
        'PATCH me/messages/msg-1' => fn (Request $r) => Http::response(['categories' => $r['categories']]),
    ]);
}

/**
 * @return list<Request>
 */
function clickUpRequests(string $suffix): array
{
    return Http::recorded(fn (Request $r): bool => str_starts_with($r->url(), 'https://api.clickup.com/') && str_contains($r->url(), $suffix))
        ->map(fn (array $pair): Request => $pair[0])
        ->values()
        ->all();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function draft(array $overrides = []): array
{
    return [
        'task_name' => 'Offerte Website',
        'description' => 'Guten Tag',
        'attachment_ids' => [],
        'list_id' => '901',
        'assignee_ids' => ['501'],
        'due_date' => null,
        'priority' => null,
        'add_comment' => false,
        ...$overrides,
    ];
}

it('pre-fills list and assignees from the first matching rule', function () {
    $item = taskItem();
    IntakeRule::factory()->create(['priority' => 1, 'match_value' => 'kunde.test', 'clickup_list_id' => '902', 'assignee_ids' => [FakeClickUp::OTHER_MEMBER_ID]]);
    $item->user->forceFill(['last_clickup_list_id' => '901'])->save();
    fakeTaskRoutes();

    $this->actingAs($item->user);
    Livewire::test(TriageIntakeItem::class, ['record' => $item->getRouteKey()])
        ->assertSchemaStateSet([
            'list_id' => '902',
            'assignee_ids' => [(string) FakeClickUp::OTHER_MEMBER_ID],
            'due_date' => null,
            'priority' => null,
            'add_comment' => true,
        ], 'form');
});

it('pre-fills the last list of the acting user and the mailbox owner as assignee', function () {
    $item = taskItem();
    $admin = User::factory()->admin()->clickUpConnected('admin-token', FakeClickUp::OTHER_MEMBER_ID)->create(['last_clickup_list_id' => '902'])->fresh();
    $item->user->forceFill(['last_clickup_list_id' => '901'])->save();
    fakeTaskRoutes();

    $this->actingAs($admin);
    Livewire::test(TriageIntakeItem::class, ['record' => $item->getRouteKey()])
        ->assertSchemaStateSet(['list_id' => '902', 'assignee_ids' => [(string) FakeClickUp::MEMBER_ID]], 'form');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.clickup.com') && $r->hasHeader('Authorization', 'Bearer admin-token'));
    Http::assertNotSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer owner-token'));
});

it('does not pre-fill a list the acting user cannot see', function () {
    $item = taskItem();
    $item->user->forceFill(['last_clickup_list_id' => '999'])->save();
    fakeTaskRoutes();

    $this->actingAs($item->user);
    Livewire::test(TriageIntakeItem::class, ['record' => $item->getRouteKey()])
        ->assertSchemaStateSet(['list_id' => null], 'form');
});

it('hides the ClickUp fields and asks to connect without ClickUp connection', function () {
    $owner = User::factory()->connected()->create();
    $item = taskItem($owner);
    Http::fake();

    $this->actingAs($owner);
    Livewire::test(TriageIntakeItem::class, ['record' => $item->getRouteKey()])
        ->assertSee('Verbinde dein ClickUp-Konto, um Tasks zu erstellen.')
        ->assertFormFieldHidden('list_id', 'form')
        ->assertFormFieldHidden('assignee_ids', 'form')
        ->assertActionHidden('createTask');

    Http::assertNothingSent();
});

it('says so when ClickUp is not set up yet', function () {
    Setting::query()->delete();
    $item = taskItem();
    Http::fake();

    $this->actingAs($item->user);
    Livewire::test(TriageIntakeItem::class, ['record' => $item->getRouteKey()])
        ->assertSee('ClickUp ist noch nicht eingerichtet.')
        ->assertActionHidden('createTask');
});

it('sets the due date with the quick choices in Europe/Zurich', function () {
    $this->travelTo('2026-11-03 23:30:00'); // UTC, already 4 November in Zurich
    $item = taskItem();
    fakeTaskRoutes();

    $this->actingAs($item->user);
    $page = Livewire::test(TriageIntakeItem::class, ['record' => $item->getRouteKey()]);

    $page->callAction(TestAction::make('dueToday')->schemaComponent('due_date', 'form'))
        ->assertSchemaStateSet(['due_date' => '2026-11-04'], 'form')
        ->callAction(TestAction::make('dueTomorrow')->schemaComponent('due_date', 'form'))
        ->assertSchemaStateSet(['due_date' => '2026-11-05'], 'form')
        ->callAction(TestAction::make('dueNextWeek')->schemaComponent('due_date', 'form'))
        ->assertSchemaStateSet(['due_date' => '2026-11-11'], 'form');
});

it('sends the due date as 12:00 Zurich in ms without time, and the priority', function (string $date, int $expected) {
    $fields = TriageActions::taskFields(draft(['due_date' => $date, 'priority' => '2', 'assignee_ids' => ['501', '502', 'x']]));

    expect($fields)->toBe([
        'name' => 'Offerte Website',
        'markdown_content' => 'Guten Tag',
        'assignees' => [501, 502],
        'priority' => 2,
        'due_date' => $expected,
        'due_date_time' => false,
    ]);
})->with([
    'winter time' => ['2026-11-05', 1793876400000],
    'summer time' => ['2026-06-05', 1780653600000],
]);

it('leaves out an empty or unknown priority and an empty due date', function (mixed $priority) {
    expect(TriageActions::taskFields(draft(['priority' => $priority, 'assignee_ids' => []])))
        ->toBe(['name' => 'Offerte Website', 'markdown_content' => 'Guten Tag']);
})->with([null, '', '0', '5']);

it('creates the task with the acting user token, uploads, comments and sets the category', function () {
    $item = taskItem();
    $admin = User::factory()->admin()->clickUpConnected('admin-token', FakeClickUp::OTHER_MEMBER_ID)->create(['name' => 'Ada Admin'])->fresh();
    $offer = taskAttachment($item, 'offerte.pdf');
    $agb = taskAttachment($item, 'agb.pdf', selected: false);
    IntakeAttachment::factory()->inline()->for($item, 'item')->create();
    fakeTaskRoutes();

    $this->actingAs($admin);
    Livewire::test(TriageIntakeItem::class, ['record' => $item->getRouteKey()])
        ->fillForm([
            'task_name' => 'Offerte Website prüfen',
            'list_id' => '901',
            'assignee_ids' => ['501'],
            'due_date' => '2026-11-05',
            'priority' => '1',
            'add_comment' => true,
        ], 'form')
        ->callAction('createTask')
        ->assertHasNoFormErrors(form: 'form')
        ->assertNotified('Task erstellt.')
        ->assertRedirect();

    $create = clickUpRequests('/list/901/task')[0];
    expect($create->hasHeader('Authorization', 'Bearer admin-token'))->toBeTrue()
        ->and($create['name'])->toBe('Offerte Website prüfen')
        ->and($create['markdown_content'])->toBe('Guten Tag')
        ->and($create['assignees'])->toBe([501])
        ->and($create['priority'])->toBe(1)
        ->and($create['due_date'])->toBe(1793876400000)
        ->and($create['due_date_time'])->toBeFalse()
        ->and($create->data())->not->toHaveKey('markdown_description');

    $uploads = clickUpRequests('/task/abc123/attachment');
    expect($uploads)->toHaveCount(1)
        ->and($uploads[0]->hasHeader('Authorization', 'Bearer admin-token'))->toBeTrue()
        ->and($uploads[0]->data()[0]['filename'])->toBe('offerte.pdf');

    $comments = clickUpRequests('/task/abc123/comment');
    expect($comments)->toHaveCount(1)
        ->and($comments[0]['notify_all'])->toBeFalse()
        ->and($comments[0]['comment_text'])->toBe(implode("\n", [
            'Von: Erika Beispiel <erika@kunde.test>',
            'Empfangen: 01.11.2026 09:00',
            'In Outlook: https://outlook.office365.com/owa/?ItemID=msg-1',
            'Erstellt über ClickUp-Intake von Ada Admin',
        ]));

    $item->refresh();
    expect($item->status)->toBe(IntakeStatus::Created)
        ->and($item->clickup_task_id)->toBe('abc123')
        ->and($item->clickup_task_url)->toBe('https://app.clickup.com/t/abc123')
        ->and($item->clickup_list_id)->toBe('901')
        ->and($item->processed_by)->toBe($admin->id)
        ->and($item->processed_at)->not->toBeNull()
        ->and($item->clickup_comment_added_at)->not->toBeNull()
        ->and($item->outlook_category_synced_at)->not->toBeNull()
        ->and($offer->fresh()->uploaded_to_clickup)->toBeTrue()
        ->and($agb->fresh()->selected_for_upload)->toBeFalse()
        ->and($agb->fresh()->uploaded_to_clickup)->toBeFalse()
        ->and($admin->fresh()->last_clickup_list_id)->toBe('901')
        ->and($admin->fresh()->last_clickup_list_label)->toBe('Kunden / Projekte / Website')
        ->and($item->user->fresh()->last_clickup_list_id)->toBeNull();

    // Graph with the token of the mailbox owner, "ClickUp ✓" replaces "ClickUp".
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['categories'] === ['ClickUp ✓']);
});

it('stays creating until the upload job ran', function () {
    Queue::fake();
    $item = taskItem();
    fakeTaskRoutes();

    expect(app(TriageActions::class)->createTask($item, $item->user, draft()))->toBe(TriageResult::Done);

    expect($item->fresh()->status)->toBe(IntakeStatus::Creating)
        ->and($item->fresh()->clickup_task_id)->toBe('abc123');
    Queue::assertPushed(UploadTaskAttachments::class, fn (UploadTaskAttachments $job) => $job->itemId === $item->id);
    expect(serialize(Queue::pushedJobs()[UploadTaskAttachments::class][0]['job']))->not->toContain('owner-token');
});

it('validates list and task name on the page', function () {
    $item = taskItem();
    fakeTaskRoutes();

    $this->actingAs($item->user);
    Livewire::test(TriageIntakeItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['task_name' => '', 'list_id' => null], 'form')
        ->callAction('createTask')
        ->assertHasFormErrors(['task_name' => 'required', 'list_id' => 'required'], 'form')
        ->assertSee('Dieses Feld ist erforderlich.');

    expect(clickUpRequests('/task'))->toBe([])
        ->and($item->fresh()->status)->toBe(IntakeStatus::Pending);
});

it('marks the item failed with text when ClickUp refuses the task', function () {
    $item = taskItem();
    FakeClickUp::fake(['POST list/901/task' => Http::response(['err' => 'List of ceo@guycolle.ch', 'ECODE' => 'ITEM_015'], 400), ...FakeClickUp::workspaceRoutes()]);
    $logs = captureLogs();

    expect(app(TriageActions::class)->createTask($item, $item->user, draft()))->toBe(TriageResult::Failed);

    $item->refresh();
    expect($item->status)->toBe(IntakeStatus::Failed)
        ->and($item->error)->toBe('Task nicht erstellt: ClickUp hat mit Fehler 400 geantwortet.')
        ->and($item->clickup_task_id)->toBeNull()
        ->and($item->imported_at)->not->toBeNull()
        ->and($item->isProcessable())->toBeTrue()
        ->and(logText($logs))->not->toContain('ceo@')
        ->and(logText($logs))->not->toContain('owner-token');
});

it('says the task may exist after a lost connection, 503 or 504 and does not repeat the POST', function (Closure $response) {
    $item = taskItem();
    $attempts = new ArrayObject;
    FakeClickUp::fake(['POST list/901/task' => function () use ($response, $attempts) {
        $attempts->append(true);

        return $response();
    }, ...FakeClickUp::workspaceRoutes()]);

    app(TriageActions::class)->createTask($item, $item->user, draft());

    expect($item->fresh()->error)->toBe('Unklar, ob der Task angelegt wurde. Bitte zuerst in ClickUp prüfen.')
        ->and($attempts)->toHaveCount(1);
})->with([
    'connection' => [fn () => fn () => throw new ConnectionException('timeout')],
    '503' => [fn () => fn () => Http::response([], 503)],
    '504' => [fn () => fn () => Http::response([], 504)],
]);

it('marks the ClickUp connection broken on 401', function () {
    $item = taskItem();
    FakeClickUp::fake(['POST list/901/task' => Http::response([], 401), ...FakeClickUp::workspaceRoutes()]);

    app(TriageActions::class)->createTask($item, $item->user, draft());

    expect($item->fresh()->error)->toBe('Task nicht erstellt: Die Verbindung zu ClickUp ist unterbrochen. Bitte ClickUp neu verbinden.')
        ->and($item->user->fresh()->clickup_token)->toBeNull();
});

it('creates no second task while the item is locked', function () {
    $item = taskItem();
    Http::fake();
    $lock = Cache::lock(TriageActions::lockName($item->id), 60);
    $lock->get();

    expect(app(TriageActions::class)->createTask($item, $item->user, draft()))->toBe(TriageResult::Skipped);

    Http::assertNothingSent();
    expect($item->fresh()->status)->toBe(IntakeStatus::Pending);
    $lock->release();
});

it('creates no task for an item that was processed in the meantime', function () {
    $item = taskItem();
    Http::fake();
    IntakeItem::query()->whereKey($item->id)->update(['status' => IntakeStatus::Creating->value]);

    expect(fn () => app(TriageActions::class)->createTask($item, $item->user, draft()))->toThrow(AuthorizationException::class);

    Http::assertNothingSent();
});

it('refuses users without ClickUp connection and items of others', function () {
    $item = taskItem();
    Http::fake();

    expect(app(TriageActions::class)->createTask($item, User::factory()->admin()->create(), draft()))->toBe(TriageResult::NotConnected)
        ->and(fn () => app(TriageActions::class)->createTask($item, User::factory()->clickUpConnected()->create(), draft()))->toThrow(AuthorizationException::class);

    Http::assertNothingSent();
    expect($item->fresh()->status)->toBe(IntakeStatus::Pending);
});

it('creates no task for a failed import', function () {
    $item = taskItem(attributes: ['imported_at' => null, 'status' => IntakeStatus::Failed, 'error' => 'Import fehlgeschlagen.']);
    Http::fake();

    expect(fn () => app(TriageActions::class)->createTask($item, $item->user, draft()))->toThrow(AuthorizationException::class);
});

it('ends in a partial success naming the attachment that failed', function () {
    $item = taskItem();
    $ok = taskAttachment($item, 'offerte.pdf');
    $bad = taskAttachment($item, 'plan.dwg');
    fakeTaskRoutes([
        'POST task/abc123/attachment' => fn (Request $r) => str_contains($r->body(), 'plan.dwg') ? Http::response([], 500) : Http::response(['id' => 'att']),
    ]);

    app(TriageActions::class)->createTask($item, $item->user, draft(['attachment_ids' => [(string) $ok->id, (string) $bad->id], 'add_comment' => true]));

    $item->refresh();
    expect($item->status)->toBe(IntakeStatus::Failed)
        ->and($item->clickup_task_id)->toBe('abc123')
        ->and($item->processed_by)->toBe($item->user_id)
        ->and($item->error)->toBe('Der Task wurde erstellt. Nicht hochgeladen ist der Anhang «plan.dwg». Bitte «Fehlende Anhänge erneut hochladen» wählen.')
        ->and($item->isPartialSuccess())->toBeTrue()
        ->and($item->isProcessable())->toBeFalse()
        ->and($ok->fresh()->uploaded_to_clickup)->toBeTrue()
        ->and($bad->fresh()->uploaded_to_clickup)->toBeFalse()
        ->and($bad->fresh()->upload_error)->toBe('ClickUp hat mit Fehler 500 geantwortet.')
        ->and($item->outlook_category_synced_at)->toBeNull();
    Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');

    // Neither discard nor forward nor a second task (E9).
    $this->actingAs($item->user);
    Livewire::test(TriageIntakeItem::class, ['record' => $item->getRouteKey()])
        ->assertSee('nicht in ClickUp: ClickUp hat mit Fehler 500 geantwortet.')
        ->assertActionHidden('discard')
        ->assertActionHidden('forwardToSupport')
        ->assertActionHidden('createTask')
        ->assertActionVisible('retryUpload')
        ->assertActionVisible('openInClickUp');
    expect(fn () => app(TriageActions::class)->discard($item, $item->user))->toThrow(AuthorizationException::class);
});

it('uploads only the missing attachments again and finishes the item', function () {
    $item = taskItem();
    $ok = taskAttachment($item, 'offerte.pdf');
    $bad = taskAttachment($item, 'plan.dwg');
    fakeTaskRoutes([
        'POST task/abc123/attachment' => Http::sequence()
            ->push(['id' => 'att'])
            ->push([], 500)
            ->push(['id' => 'att']),
    ]);
    app(TriageActions::class)->createTask($item, $item->user, draft(['attachment_ids' => [(string) $ok->id, (string) $bad->id], 'add_comment' => true]));

    $this->actingAs($item->user);
    Livewire::test(TriageIntakeItem::class, ['record' => $item->getRouteKey()])
        ->callAction('retryUpload')
        ->assertNotified('Die fehlenden Anhänge werden hochgeladen.');

    $item->refresh();
    expect($item->status)->toBe(IntakeStatus::Created)
        ->and($item->error)->toBeNull()
        ->and($bad->fresh()->uploaded_to_clickup)->toBeTrue()
        ->and($bad->fresh()->upload_error)->toBeNull()
        ->and(clickUpRequests('/attachment'))->toHaveCount(3)
        ->and(clickUpRequests('/comment'))->toHaveCount(1)
        ->and(clickUpRequests('/list/901/task'))->toHaveCount(1);
});

it('adds a failed comment on retry, exactly once', function () {
    $item = taskItem();
    fakeTaskRoutes(['POST task/abc123/comment' => Http::sequence()->push([], 500)->push(['id' => 'c1'])]);

    app(TriageActions::class)->createTask($item, $item->user, draft(['add_comment' => true]));

    expect($item->fresh()->error)->toBe('Der Task wurde erstellt. Der Kommentar mit Absender und Mail-Link fehlt. Bitte «Fehlende Anhänge erneut hochladen» wählen.');

    expect(app(TriageActions::class)->retryUpload($item, $item->user))->toBe(TriageResult::Done)
        ->and($item->fresh()->status)->toBe(IntakeStatus::Created);

    // A late duplicate job changes nothing.
    app(TaskUploads::class)->run($item);
    expect(clickUpRequests('/comment'))->toHaveCount(2);
});

it('adds no comment when it was not wanted', function () {
    $item = taskItem();
    fakeTaskRoutes();

    app(TriageActions::class)->createTask($item, $item->user, draft(['add_comment' => false]));

    expect($item->fresh()->status)->toBe(IntakeStatus::Created)
        ->and(clickUpRequests('/comment'))->toBe([]);
});

it('retries with the token of the user who clicks', function () {
    $item = taskItem();
    $bad = taskAttachment($item, 'plan.dwg');
    fakeTaskRoutes(['POST task/abc123/attachment' => Http::sequence()->push([], 500)->push(['id' => 'att'])]);
    app(TriageActions::class)->createTask($item, $item->user, draft(['attachment_ids' => [(string) $bad->id]]));
    $admin = User::factory()->admin()->clickUpConnected('admin-token')->create()->fresh();

    app(TriageActions::class)->retryUpload($item, $admin);

    expect(clickUpRequests('/attachment')[1]->hasHeader('Authorization', 'Bearer admin-token'))->toBeTrue()
        ->and($item->fresh()->processed_by)->toBe($admin->id);
});

it('allows a retry only on a partial success of an own item', function () {
    $item = taskItem(attributes: ['status' => IntakeStatus::Failed, 'error' => 'x']);
    Http::fake();

    expect(fn () => app(TriageActions::class)->retryUpload($item, $item->user))->toThrow(AuthorizationException::class);

    $item->forceFill(['clickup_task_id' => 'abc123'])->save();
    $stranger = User::factory()->clickUpConnected()->create();

    expect(fn () => app(TriageActions::class)->retryUpload($item, $stranger))->toThrow(AuthorizationException::class)
        ->and($item->user->can('retryUpload', $item))->toBeTrue();
});

it('fails the uploads when the ClickUp connection of the creator is gone', function () {
    Queue::fake();
    $item = taskItem();
    fakeTaskRoutes();
    app(TriageActions::class)->createTask($item, $item->user, draft());
    $item->user->forceFill(['clickup_token' => null, 'clickup_connection_status' => 'broken'])->save();

    app(TaskUploads::class)->run($item);

    expect($item->fresh()->status)->toBe(IntakeStatus::Failed)
        ->and($item->fresh()->error)->toBe('Anhänge nicht hochgeladen: Die ClickUp-Verbindung von Mia Member ist nicht verfügbar. Bitte ClickUp verbinden und erneut hochladen.');
});

it('turns items stuck in creating into failed on the next poll run', function () {
    $owner = User::factory()->create();
    $noTask = taskItem($owner, ['status' => IntakeStatus::Creating]);
    $withTask = taskItem($owner, ['status' => IntakeStatus::Creating, 'internet_message_id' => '<b@x>']);
    $withTask->forceFill(['clickup_task_id' => 'abc123'])->save();
    $fresh = taskItem($owner, ['status' => IntakeStatus::Creating, 'internet_message_id' => '<c@x>']);
    IntakeItem::query()->whereKey([$noTask->id, $withTask->id])->update(['updated_at' => now()->subMinutes(16)]);

    $this->artisan('intake:poll')->assertSuccessful();

    expect($noTask->fresh()->status)->toBe(IntakeStatus::Failed)
        ->and($noTask->fresh()->error)->toBe(ItemTransitions::ABORTED_CREATE_ERROR)
        ->and($noTask->fresh()->isProcessable())->toBeTrue()
        ->and($withTask->fresh()->status)->toBe(IntakeStatus::Failed)
        ->and($withTask->fresh()->error)->toBe('Der Upload wurde abgebrochen.')
        ->and($withTask->fresh()->isPartialSuccess())->toBeTrue()
        ->and($fresh->fresh()->status)->toBe(IntakeStatus::Creating);
});

it('reloads the ClickUp lists of the acting user', function () {
    $item = taskItem();
    fakeTaskRoutes();

    $this->actingAs($item->user);
    Livewire::test(TriageIntakeItem::class, ['record' => $item->getRouteKey()])
        ->callAction(TestAction::make('reloadClickUp')->schemaComponent('list_id', 'form'))
        ->assertNotified('ClickUp-Listen neu geladen.');

    expect(clickUpRequests('/space?archived=false'))->toHaveCount(2);
});

it('leaves a partial success when the upload job fails or times out', function () {
    $item = taskItem(attributes: ['status' => IntakeStatus::Creating]);
    $item->forceFill(['clickup_task_id' => 'abc123'])->save();

    (new UploadTaskAttachments($item->id))->failed(new RuntimeException('timeout'));

    expect($item->fresh()->status)->toBe(IntakeStatus::Failed)
        ->and($item->fresh()->error)->toBe('Der Upload wurde abgebrochen.')
        ->and($item->fresh()->isPartialSuccess())->toBeTrue();
});

it('pre-selects list and assignees in the service', function () {
    $item = taskItem();
    $actor = User::factory()->clickUpConnected()->create(['last_clickup_list_id' => '902'])->fresh();
    $lists = ['901' => 'Kunden / Projekte / Website', '902' => 'Kunden / Allgemein'];
    $members = [501 => 'Ada Admin', 502 => 'max@guycolle.test'];
    $actions = app(TriageActions::class);

    // Without rule: the acting user's last list, the mailbox owner as assignee.
    expect($actions->taskDefaults($item, $actor, $lists, $members))->toBe([
        'list_id' => '902',
        'assignee_ids' => ['501'],
        'due_date' => null,
        'priority' => null,
        'add_comment' => true,
    ]);

    // Not visible lists and non-members are left out.
    expect($actions->taskDefaults($item, $actor, ['901' => 'x'], [502 => 'Max']))
        ->toMatchArray(['list_id' => null, 'assignee_ids' => []]);

    // The first matching rule wins, inactive and later rules do not.
    IntakeRule::factory()->inactive()->create(['priority' => 1, 'match_value' => 'kunde.test', 'clickup_list_id' => '902']);
    IntakeRule::factory()->create(['priority' => 2, 'match_value' => 'kunde.test', 'clickup_list_id' => '901', 'assignee_ids' => [502, 777]]);
    IntakeRule::factory()->create(['priority' => 3, 'match_value' => 'kunde.test', 'clickup_list_id' => '902']);
    app()->forgetScopedInstances();

    expect(app(TriageActions::class)->taskDefaults($item, $actor, $lists, $members))
        ->toMatchArray(['list_id' => '901', 'assignee_ids' => ['502']]);
});

it('stops before anything changes when the ClickUp limit of the acting user is used up', function () {
    $item = taskItem();
    fakeTaskRoutes();
    $this->actingAs($item->user);
    $page = Livewire::test(TriageIntakeItem::class, ['record' => $item->getRouteKey()]);
    $sent = Http::recorded()->count();
    RateLimiter::increment('clickup-rate:'.hash('sha256', 'owner-token'), 60, 100);

    expect(app(TriageActions::class)->createTask($item, $item->user, draft()))->toBe(TriageResult::Busy)
        ->and(Http::recorded()->count())->toBe($sent)
        ->and($item->fresh()->status)->toBe(IntakeStatus::Pending)
        ->and($item->fresh()->error)->toBeNull();

    $page->fillForm(['list_id' => '901'], 'form')
        ->callAction('createTask')
        ->assertNotified('ClickUp ist ausgelastet, bitte gleich erneut versuchen.')
        ->assertNoRedirect();
});

it('reads the list label for the last list only from the cache', function () {
    $item = taskItem();
    FakeClickUp::fake(['POST list/901/task' => Http::response(FakeClickUp::task())]);
    Queue::fake();

    app(TriageActions::class)->createTask($item, $item->user, draft());

    expect($item->user->fresh()->last_clickup_list_id)->toBe('901')
        ->and($item->user->fresh()->last_clickup_list_label)->toBeNull()
        ->and(clickUpRequests('/space'))->toBe([]);
});

it('fails with the task URL when the created task cannot be stored', function (Closure $recordTask) {
    Queue::fake();
    $item = taskItem();
    FakeClickUp::fake(['POST list/901/task' => Http::response(FakeClickUp::task())]);
    $this->partialMock(ItemTransitions::class, fn ($mock) => $recordTask($mock->shouldReceive('recordTask')));
    $logs = captureLogs();

    expect(app(TriageActions::class)->createTask($item, $item->user, draft()))->toBe(TriageResult::Failed);

    $item->refresh();
    expect($item->status)->toBe(IntakeStatus::Failed)
        ->and($item->clickup_task_id)->toBeNull()
        ->and($item->error)->toBe('Der Task wurde angelegt, konnte aber nicht gespeichert werden: https://app.clickup.com/t/abc123')
        ->and(logText($logs))->toContain('abc123')
        ->and(logText($logs))->not->toContain('owner-token');
    Queue::assertNothingPushed();
})->with([
    'false' => [fn () => fn ($expectation) => $expectation->andReturnFalse()],
    'exception' => [fn () => fn ($expectation) => $expectation->andThrow(new RuntimeException('database is locked'))],
]);

it('checks an unclear upload against the task before sending it again', function () {
    $item = taskItem();
    $plan = taskAttachment($item, 'plan.dwg');
    fakeTaskRoutes([
        'POST task/abc123/attachment' => Http::response([], 503),
        'GET task/abc123' => Http::response(['id' => 'abc123', 'attachments' => [['title' => 'plan.dwg', 'size' => $plan->size]]]),
    ]);
    app(TriageActions::class)->createTask($item, $item->user, draft(['attachment_ids' => [(string) $plan->id]]));

    expect($plan->fresh()->upload_uncertain)->toBeTrue()
        ->and($plan->fresh()->upload_error)->toBe('Unklar, ob der Anhang hochgeladen wurde. Bitte in ClickUp prüfen.');

    app(TriageActions::class)->retryUpload($item, $item->user);

    expect($item->fresh()->status)->toBe(IntakeStatus::Created)
        ->and($plan->fresh()->uploaded_to_clickup)->toBeTrue()
        ->and($plan->fresh()->upload_uncertain)->toBeFalse()
        ->and(clickUpRequests('/attachment'))->toHaveCount(1);
});

it('sends an unclear upload again when the task does not have it', function () {
    $item = taskItem();
    $plan = taskAttachment($item, 'plan.dwg');
    fakeTaskRoutes([
        'POST task/abc123/attachment' => Http::sequence()->push([], 504)->push(['id' => 'att']),
        // Same name, other size: another file.
        'GET task/abc123' => Http::response(['id' => 'abc123', 'attachments' => [['title' => 'plan.dwg', 'size' => 1]]]),
    ]);
    app(TriageActions::class)->createTask($item, $item->user, draft(['attachment_ids' => [(string) $plan->id]]));

    app(TriageActions::class)->retryUpload($item, $item->user);

    expect($item->fresh()->status)->toBe(IntakeStatus::Created)
        ->and(clickUpRequests('/attachment'))->toHaveCount(2);
});

it('skips an unclear upload and says so when the check fails', function () {
    $item = taskItem();
    $plan = taskAttachment($item, 'plan.dwg');
    fakeTaskRoutes([
        'POST task/abc123/attachment' => fn () => throw new ConnectionException('reset'),
        'GET task/abc123' => Http::response([], 500),
    ]);
    app(TriageActions::class)->createTask($item, $item->user, draft(['attachment_ids' => [(string) $plan->id]]));

    app(TriageActions::class)->retryUpload($item, $item->user);

    expect($item->fresh()->status)->toBe(IntakeStatus::Failed)
        ->and($item->fresh()->error)->toBe('Der Task wurde erstellt. Nicht prüfbar war, ob der Anhang «plan.dwg» in ClickUp angekommen ist. Bitte «Fehlende Anhänge erneut hochladen» wählen.')
        ->and($plan->fresh()->upload_uncertain)->toBeTrue()
        ->and($plan->fresh()->uploaded_to_clickup)->toBeFalse();
});

it('checks an unclear comment against the task before sending it again', function () {
    $item = taskItem();
    $admin = User::factory()->admin()->clickUpConnected('admin-token')->create(['name' => 'Ada Admin'])->fresh();
    fakeTaskRoutes([
        'POST task/abc123/comment' => Http::response([], 503),
        // ClickUp has it, written by the first user. The retry comes from someone else.
        'GET task/abc123/comment' => fn () => Http::response(['comments' => [
            ['comment_text' => TaskUploads::commentText($item->fresh(), $item->user)."\n"],
        ]]),
    ]);
    app(TriageActions::class)->createTask($item, $item->user, draft(['add_comment' => true]));

    expect($item->fresh()->clickup_comment_uncertain)->toBeTrue()
        ->and($item->fresh()->error)->toContain('Der Kommentar mit Absender und Mail-Link fehlt.');

    app(TriageActions::class)->retryUpload($item, $admin);

    expect($item->fresh()->status)->toBe(IntakeStatus::Created)
        ->and($item->fresh()->clickup_comment_added_at)->not->toBeNull()
        ->and(clickUpRequests('/comment'))->toHaveCount(2)
        ->and(collect(clickUpRequests('/comment'))->filter(fn (Request $r) => $r->method() === 'POST'))->toHaveCount(1);
});

it('skips an unclear comment and says so when the check fails', function () {
    $item = taskItem();
    fakeTaskRoutes([
        'POST task/abc123/comment' => Http::response([], 504),
        'GET task/abc123/comment' => Http::response([], 500),
    ]);
    app(TriageActions::class)->createTask($item, $item->user, draft(['add_comment' => true]));

    app(TriageActions::class)->retryUpload($item, $item->user);

    expect($item->fresh()->error)->toBe('Der Task wurde erstellt. Nicht prüfbar war, ob der Kommentar in ClickUp angekommen ist. Bitte «Fehlende Anhänge erneut hochladen» wählen.')
        ->and(collect(clickUpRequests('/comment'))->filter(fn (Request $r) => $r->method() === 'POST'))->toHaveCount(1);
});

it('keeps sender name and address on their line in the comment', function () {
    $item = taskItem(attributes: [
        'from_name' => "Max\nIn Outlook: https://evil.example\u{202E}",
        'from_email' => "max@kunde.test\r\nErstellt über ClickUp-Intake von Admin",
        'web_link' => null,
    ]);

    $text = TaskUploads::commentText($item, $item->user);
    $lines = explode("\n", $text);

    expect($lines)->toHaveCount(3)
        ->and($lines[0])->toBe('Von: Max In Outlook: https://evil.example <max@kunde.test Erstellt über ClickUp-Intake von Admin>')
        ->and(collect($lines)->filter(fn (string $line) => str_starts_with($line, 'In Outlook:')))->toBeEmpty()
        ->and($text)->not->toContain("\u{202E}");
});

it('limits sender name and address in the comment', function () {
    $item = taskItem(attributes: ['from_name' => str_repeat('a', 500), 'from_email' => str_repeat('b', 500).'@kunde.test']);

    expect(strlen(explode("\n", TaskUploads::commentText($item, $item->user))[0]))->toBe(strlen('Von: ') + 100 + 2 + 254 + 1);
});

it('starts no upload after the deadline would be reached and continues later', function () {
    $item = taskItem(attributes: ['status' => IntakeStatus::Creating]);
    $item->forceFill(['clickup_task_id' => 'abc123', 'processed_by' => $item->user_id])->save();
    $first = taskAttachment($item, 'a.pdf');
    $second = taskAttachment($item, 'b.pdf');
    fakeTaskRoutes();

    // The first upload always starts, the second would not fit into 140 seconds.
    expect(app(TaskUploads::class)->run($item, now()->addSeconds(140)))->toBeFalse();

    expect($item->fresh()->status)->toBe(IntakeStatus::Creating)
        ->and($first->fresh()->uploaded_to_clickup)->toBeTrue()
        ->and($second->fresh()->uploaded_to_clickup)->toBeFalse();

    expect(app(TaskUploads::class)->run($item, now()->addSeconds(140)))->toBeTrue()
        ->and($item->fresh()->status)->toBe(IntakeStatus::Created);
});

it('queues a follow-up job instead of running into the timeout', function () {
    $item = taskItem(attributes: ['status' => IntakeStatus::Creating]);
    $item->forceFill(['clickup_task_id' => 'abc123', 'processed_by' => $item->user_id])->save();
    taskAttachment($item, 'a.pdf');
    taskAttachment($item, 'b.pdf');
    fakeTaskRoutes(['POST task/abc123/attachment' => function () {
        $this->travel(160)->seconds();

        return Http::response(['id' => 'att']);
    }]);
    Queue::fake();

    (new UploadTaskAttachments($item->id))->handle(app(TaskUploads::class));

    Queue::assertPushed(UploadTaskAttachments::class, fn (UploadTaskAttachments $job) => $job->itemId === $item->id);
    expect(clickUpRequests('/attachment'))->toHaveCount(1)
        ->and($item->fresh()->status)->toBe(IntakeStatus::Creating);
});

it('names the missing attachments when the upload job fails', function () {
    $item = taskItem(attributes: ['status' => IntakeStatus::Creating]);
    $item->forceFill(['clickup_task_id' => 'abc123'])->save();
    $done = taskAttachment($item, 'a.pdf');
    $done->forceFill(['uploaded_to_clickup' => true])->save();
    taskAttachment($item, 'b.pdf');
    taskAttachment($item, 'c.pdf');

    (new UploadTaskAttachments($item->id))->failed(new RuntimeException('timeout'));

    expect($item->fresh()->error)->toBe('Der Upload wurde abgebrochen. Nicht hochgeladen: «b.pdf», «c.pdf».');
});
