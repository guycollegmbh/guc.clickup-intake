<?php

namespace App\Filament\Pages;

use App\ClickUp\ClickUpApp;
use App\ClickUp\UserClickUp;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * Admin page "ClickUp" (E2): OAuth app credentials and the workspace of this installation,
 * stored encrypted in `settings` by ClickUpApp (R11c). The secret is never shown again, an empty
 * field keeps it. Workspaces come from the admin's own connected ClickUp account.
 *
 * Admin only, checked on every Livewire request (canAccess, R12).
 */
class ClickUpSettings extends Page
{
    protected static ?string $slug = 'clickup';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'ClickUp';

    protected static ?string $title = 'ClickUp';

    protected static ?int $navigationSort = 4;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /**
     * Workspaces of the admin's ClickUp account, id => name. Locked: the select is validated
     * against it.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $teams = [];

    #[Locked]
    public ?string $teamsError = null;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->active && $user->isAdmin();
    }

    public function mount(): void
    {
        $app = app(ClickUpApp::class);
        $this->loadTeams();

        $this->form->fill([
            'client_id' => $app->clientId(),
            'client_secret' => null,
            'team_id' => $app->teamId(),
        ]);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        $app = app(ClickUpApp::class);
        $connected = app(UserClickUp::class)->isConnected($this->admin());

        return $schema->components([
            Section::make('OAuth-App')
                ->description('Ein ClickUp-Admin legt die App unter Settings, Integrations, ClickUp API an und trägt dort diese Redirect-URL ein.')
                ->schema([
                    TextInput::make('redirect_uri')
                        ->label('Redirect-URL')
                        ->formatStateUsing(fn (): string => $app->redirectUri())
                        ->readOnly()
                        ->copyable()
                        ->dehydrated(false),
                    TextInput::make('client_id')
                        ->label('Client-ID')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('client_secret')
                        ->label('Client-Secret')
                        ->password()
                        ->autocomplete('new-password')
                        ->maxLength(255)
                        ->required(fn (): bool => blank($app->clientSecret()))
                        ->placeholder(fn (): ?string => filled($app->clientSecret()) ? 'Gespeichert. Leer lassen, um es zu behalten.' : null),
                ]),
            Section::make('Workspace')
                ->description('Regeln, Listen und Mitglieder beziehen sich auf diesen Workspace. Wer ClickUp verbindet, muss Zugriff darauf haben.')
                ->schema([
                    Callout::make('Verbinde dein ClickUp-Konto, um den Workspace zu wählen.')
                        ->warning()
                        ->controlActions([
                            Action::make('connectClickUp')
                                ->label('Mit ClickUp verbinden')
                                ->url(route('clickup.connect')),
                        ])
                        ->visible(fn (): bool => ! $connected && $app->hasCredentials()),
                    Callout::make('Zuerst Client-ID und Client-Secret speichern.')
                        ->info()
                        ->visible(fn (): bool => ! $app->hasCredentials()),
                    Callout::make(fn (): string => (string) $this->teamsError)
                        ->danger()
                        ->visible(fn (): bool => $this->teamsError !== null),
                    Select::make('team_id')
                        ->label('Workspace')
                        ->options(fn (): array => $this->teams)
                        ->required(fn (): bool => $this->teams !== [])
                        ->visible(fn (): bool => $connected && $this->teams !== []),
                    Callout::make(fn (): string => $app->isConfigured()
                        ? 'Eingerichtet mit dem Workspace «'.$app->teamName().'».'
                        : 'Noch nicht eingerichtet.')
                        ->color(fn (): string => $app->isConfigured() ? 'success' : 'gray'),
                ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Speichern')->submit('save'),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $app = app(ClickUpApp::class);

        $app->storeCredentials((string) $data['client_id'], $data['client_secret'] ?? null);

        $teamId = isset($data['team_id']) ? (string) $data['team_id'] : null;

        if ($teamId !== null && array_key_exists($teamId, $this->teams)) {
            $app->storeWorkspace($teamId, $this->teams[$teamId]);
        }

        $this->form->fill([...$data, 'client_secret' => null]);

        Notification::make()->title('Gespeichert.')->success()->send();
    }

    private function loadTeams(): void
    {
        $user = $this->admin();
        $clickUp = app(UserClickUp::class);

        if (! $clickUp->isConnected($user)) {
            return;
        }

        try {
            $this->teams = array_map('strval', $clickUp->teams($user));
        } catch (Throwable) {
            $this->teamsError = 'Die Workspaces konnten nicht von ClickUp geladen werden.';
        }
    }

    private function admin(): User
    {
        $user = Auth::user();
        assert($user instanceof User);

        return $user;
    }
}
