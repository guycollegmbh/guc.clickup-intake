<?php

use App\Enums\ConnectionStatus;
use App\Enums\IntakeStatus;
use App\Filament\Resources\IntakeItems\IntakeItemResource;
use App\Filament\Resources\IntakeItems\Pages\TriageIntakeItem;
use App\Intake\MailboxPoller;
use App\Intake\TriageActions;
use App\Models\IntakeAttachment;
use App\Models\IntakeItem;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\FakeGraph;

const SUPPORT_ADDRESS = 'support@guycolle.test';

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Sleep::fake();
    Storage::fake('local');
    config(['intake.support_address' => SUPPORT_ADDRESS]);
});

/**
 * An imported item as the poll leaves it for the FakeGraph mail msg-1.
 *
 * @param  array<string, mixed>  $attributes
 */
function triageItem(?User $owner = null, array $attributes = []): IntakeItem
{
    $owner ??= User::factory()->connected()->create();

    return IntakeItem::factory()->for($owner)->create([
        'graph_message_id' => 'msg-1',
        'internet_message_id' => '<msg-1@mail.example.test>',
        'subject' => 'AW: WG: Offerte Website',
        'from_name' => 'Erika Beispiel',
        'from_email' => 'erika@example.test',
        'received_at' => '2026-11-01 08:00:00',
        'to_json' => [['name' => 'Team', 'address' => 'team@guycolle.test']],
        'cc_json' => [['name' => 'Max Muster', 'address' => 'max@example.test']],
        'body_html' => '<p>Guten Tag</p><script>alert(1)</script><p><a href="javascript:alert(2)">Link</a></p>',
        ...$attributes,
    ]);
}

/**
 * Category routes for msg-1: Outlook confirms what was sent.
 *
 * @return array<string, mixed>
 */
function categoryRoutes(): array
{
    return [
        'GET me/messages/msg-1?*' => Http::response(['categories' => ['ClickUp', 'Rot']]),
        'PATCH me/messages/msg-1' => fn (Request $r) => Http::response(['categories' => $r['categories']]),
    ];
}

function forwardRequest(): ?Request
{
    return Http::recorded(fn (Request $r): bool => $r->method() === 'POST' && str_ends_with($r->url(), '/forward'))->first()[0] ?? null;
}

it('shows the mail header and pre-fills the task fields', function () {
    $item = triageItem(attributes: ['status' => IntakeStatus::Failed, 'error' => 'Weiterleitung fehlgeschlagen: Test.']);
    $file = IntakeAttachment::factory()->for($item, 'item')->create(['name' => 'offerte.pdf', 'storage_path' => 'intake/x/1', 'size' => 2048]);
    $file->forceFill(['selected_for_upload' => true])->save();
    $notSelected = IntakeAttachment::factory()->for($item, 'item')->create(['name' => 'agb.pdf', 'storage_path' => 'intake/x/2']);
    $broken = IntakeAttachment::factory()->for($item, 'item')->create(['name' => 'gross.zip']);
    $broken->forceFill(['error' => 'Der Anhang ist grösser als 50 MB.', 'selected_for_upload' => true])->save();
    $link = IntakeAttachment::factory()->for($item, 'item')->create(['name' => 'Plan.xlsx', 'type' => 'reference']);
    IntakeAttachment::factory()->inline()->for($item, 'item')->create(['storage_path' => 'intake/x/5']);

    $this->actingAs($item->user);
    Livewire::test(TriageIntakeItem::class, ['record' => $item->getRouteKey()])
        ->assertOk()
        ->assertSee('Erika Beispiel <erika@example.test>')
        ->assertSee('Team <team@guycolle.test>')
        ->assertSee('Max Muster <max@example.test>')
        ->assertSee('01.11.2026 09:00')
        ->assertSee('Fehlgeschlagen')
        ->assertSee('Weiterleitung fehlgeschlagen: Test.')
        ->assertSee('Link in der Original-Mail')
        ->assertSee('Der Anhang ist grösser als 50 MB.')
        ->assertDontSee('image001.png')
        ->assertSchemaStateSet([
            'task_name' => 'Offerte Website',
            'description' => "Guten Tag\n\nLink",
            'attachment_ids' => [(string) $file->id],
        ], 'form');

    expect(collect([$notSelected, $broken, $link])->pluck('id'))->toHaveCount(3);
});

it('embeds the original only as a sandboxed iframe and never the mail HTML itself', function () {
    $item = triageItem(attributes: ['body_html' => '<p>Unique-Body-Marker</p><script>alert(1)</script>']);

    $this->actingAs($item->user)
        ->get(IntakeItemResource::getUrl('view', ['record' => $item]))
        ->assertOk()
        ->assertSee('sandbox=""', escape: false)
        ->assertSee(route('intake.original', ['item' => $item->id]), escape: false)
        ->assertDontSee('<script>alert(1)</script>', escape: false)
        ->assertDontSee('<p>Unique-Body-Marker</p>', escape: false);
});

it('opens the mail in Outlook in a new window without opener', function () {
    $item = triageItem(attributes: ['web_link' => 'https://outlook.office365.com/owa/?ItemID=abc']);

    $this->actingAs($item->user)
        ->get(IntakeItemResource::getUrl('view', ['record' => $item]))
        ->assertSee('href="https://outlook.office365.com/owa/?ItemID=abc"', escape: false)
        ->assertSee('target="_blank"', escape: false)
        ->assertSee('rel="noopener noreferrer"', escape: false);
});

it('offers no Outlook link for anything but https', function () {
    $item = triageItem(attributes: ['web_link' => 'javascript:alert(1)']);

    $this->actingAs($item->user);
    Livewire::test(TriageIntakeItem::class, ['record' => $item->getRouteKey()])->assertActionHidden('openInOutlook');
});

it('denies the triage page of a foreign item to a member', function () {
    $item = triageItem();

    $this->actingAs(User::factory()->create())
        ->get(IntakeItemResource::getUrl('view', ['record' => $item]))
        ->assertNotFound();
});

it('discards the item and removes the category', function () {
    $item = triageItem();
    FakeGraph::fake(categoryRoutes());

    $this->actingAs($item->user);
    Livewire::test(TriageIntakeItem::class, ['record' => $item->getRouteKey()])
        ->callAction('discard')
        ->assertNotified('Mail verworfen.')
        ->assertRedirect(IntakeItemResource::getUrl('index'));

    $item->refresh();
    expect($item->status)->toBe(IntakeStatus::Discarded)
        ->and($item->processed_by)->toBe($item->user_id)
        ->and($item->processed_at)->not->toBeNull()
        ->and($item->outlook_category_synced_at)->not->toBeNull();
    Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH' && $r['categories'] === ['Rot']);
});

it('keeps the item discarded when the category update fails, for the poll to repeat', function () {
    $item = triageItem();
    FakeGraph::fake(['GET me/messages/msg-1?*' => Http::response([], 500)]);

    $this->actingAs($item->user);
    Livewire::test(TriageIntakeItem::class, ['record' => $item->getRouteKey()])
        ->callAction('discard')
        ->assertNotified('Mail verworfen. Die Kategorie in Outlook wird beim nächsten Abgleich angepasst.');

    expect($item->fresh()->status)->toBe(IntakeStatus::Discarded)
        ->and($item->fresh()->outlook_category_synced_at)->toBeNull();
});

it('forwards to the configured support address only, with comment and category', function () {
    $item = triageItem();
    FakeGraph::fake([
        'POST me/messages/msg-1/forward' => Http::response(null, 202),
        ...categoryRoutes(),
    ]);

    $this->actingAs($item->user);
    Livewire::test(TriageIntakeItem::class, ['record' => $item->getRouteKey()])
        ->assertActionVisible('forwardToSupport')
        ->callAction('forwardToSupport')
        ->assertNotified('An den Support weitergeleitet.');

    $request = forwardRequest();
    expect($request)->not->toBeNull()
        ->and($request->header('Authorization')[0])->toBe('Bearer access-token-valid')
        ->and($request->data())->toBe([
            'comment' => 'Über ClickUp-Intake weitergeleitet von '.$item->user->name,
            'toRecipients' => [['emailAddress' => ['address' => SUPPORT_ADDRESS]]],
        ]);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'PATCH' && $r['categories'] === ['Rot', 'Support ✓']);

    $item->refresh();
    expect($item->status)->toBe(IntakeStatus::Forwarded)
        ->and($item->processed_by)->toBe($item->user_id)
        ->and($item->outlook_category_synced_at)->not->toBeNull();
});

it('forwards an item of another user with that user\'s token and names the admin', function () {
    $item = triageItem();
    $admin = User::factory()->admin()->connected()->create(['name' => 'Ada Admin']);
    $admin->forceFill(['ms_access_token' => 'admin-token'])->save();
    FakeGraph::fake([
        'POST me/messages/msg-1/forward' => Http::response(null, 202),
        ...categoryRoutes(),
    ]);

    $this->actingAs($admin);
    Livewire::test(TriageIntakeItem::class, ['record' => $item->getRouteKey()])->callAction('forwardToSupport');

    $request = forwardRequest();
    expect($request->header('Authorization')[0])->toBe('Bearer access-token-valid')
        ->and($request['comment'])->toBe('Über ClickUp-Intake weitergeleitet von Ada Admin')
        ->and($item->fresh()->status)->toBe(IntakeStatus::Forwarded)
        ->and($item->fresh()->processed_by)->toBe($admin->id);
    Http::assertNotSent(fn (Request $r): bool => $r->header('Authorization') === ['Bearer admin-token']);
});

it('marks the item failed with a text when forwarding fails, and the poll does not import it again', function () {
    $item = triageItem();
    FakeGraph::fake([
        'POST me/messages/msg-1/forward' => Http::response(['error' => ['code' => 'ErrorAccessDenied']], 403),
        'GET me/messages?$filter=*' => Http::response(['value' => [FakeGraph::summary('msg-1')]]),
    ]);

    $this->actingAs($item->user);
    Livewire::test(TriageIntakeItem::class, ['record' => $item->getRouteKey()])
        ->callAction('forwardToSupport')
        ->assertNotified('Fehlgeschlagen')
        ->assertNoRedirect();

    $item->refresh();
    expect($item->status)->toBe(IntakeStatus::Failed)
        ->and($item->error)->toBe('Weiterleitung fehlgeschlagen: Microsoft 365 hat mit Fehler 403 geantwortet.')
        ->and($item->imported_at)->not->toBeNull()
        ->and($item->processed_at)->toBeNull();

    // Next poll: the mail still carries "ClickUp", the failed item is left alone (E8).
    app(MailboxPoller::class)->poll($item->user->fresh());

    expect($item->fresh()->status)->toBe(IntakeStatus::Failed)
        ->and($item->fresh()->error)->toBe('Weiterleitung fehlgeschlagen: Microsoft 365 hat mit Fehler 403 geantwortet.');
    Http::assertNotSent(fn (Request $r): bool => $r->method() === 'GET' && str_contains(FakeGraph::path($r), 'me/messages/msg-1'));
});

it('marks the item failed when the mailbox owner has no Microsoft connection', function () {
    $owner = User::factory()->create(['ms_connection_status' => ConnectionStatus::Broken]);
    $item = triageItem($owner);
    Http::fake();

    $this->actingAs(User::factory()->admin()->create());
    Livewire::test(TriageIntakeItem::class, ['record' => $item->getRouteKey()])->callAction('forwardToSupport');

    expect($item->fresh()->status)->toBe(IntakeStatus::Failed)
        ->and($item->fresh()->error)->toContain('Verbindung des Postfachs zu Microsoft 365 ist unterbrochen');
    Http::assertNothingSent();
});

it('hides forwarding without a valid support address', function (?string $address) {
    config(['intake.support_address' => $address]);
    $item = triageItem();

    $this->actingAs($item->user);
    Livewire::test(TriageIntakeItem::class, ['record' => $item->getRouteKey()])
        ->assertActionHidden('forwardToSupport')
        ->assertActionVisible('discard');
})->with([null, '', 'kein-mail']);

it('offers no triage action on finished items', function (IntakeStatus $status) {
    $item = triageItem(attributes: ['status' => $status, 'processed_at' => now()]);
    Http::fake();

    $this->actingAs($item->user);
    Livewire::test(TriageIntakeItem::class, ['record' => $item->getRouteKey()])
        ->assertActionHidden('discard')
        ->assertActionHidden('forwardToSupport')
        ->assertSchemaComponentStateSet('task_name', 'Offerte Website', 'form');

    expect(fn () => app(TriageActions::class)->discard($item, $item->user))->toThrow(AuthorizationException::class)
        ->and(fn () => app(TriageActions::class)->forwardToSupport($item, $item->user))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
    expect($item->fresh()->status)->toBe($status);
})->with([IntakeStatus::Created, IntakeStatus::Discarded, IntakeStatus::Forwarded, IntakeStatus::Creating]);

it('lets a member not act on a foreign item through the service', function () {
    $item = triageItem();
    Http::fake();

    expect(fn () => app(TriageActions::class)->discard($item, User::factory()->create()))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('shows who processed a finished item and when', function () {
    $processor = User::factory()->create(['name' => 'Paula Prüfer']);
    $item = triageItem(attributes: [
        'status' => IntakeStatus::Discarded,
        'processed_at' => '2026-11-02 09:30:00',
        'processed_by' => $processor->id,
    ]);

    $this->actingAs($item->user);
    Livewire::test(TriageIntakeItem::class, ['record' => $item->getRouteKey()])
        ->assertSee('02.11.2026 10:30 Paula Prüfer')
        ->assertSee('Verworfen');
});
