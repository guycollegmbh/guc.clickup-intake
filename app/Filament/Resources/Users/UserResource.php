<?php

namespace App\Filament\Resources\Users;

use App\Enums\ConnectionStatus;
use App\Enums\Role;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Users\UserAdministration;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Auth;

/**
 * "Benutzer": admin only (UserPolicy). Users come from signing in, they are never created or
 * deleted here. Role and active are changed through UserAdministration only.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'benutzer';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Benutzer';

    protected static ?string $modelLabel = 'Benutzer';

    protected static ?string $pluralModelLabel = 'Benutzer';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('mailbox_upn')
                    ->label('UPN')
                    ->searchable(),
                TextColumn::make('role')
                    ->label('Rolle')
                    ->badge()
                    ->formatStateUsing(fn (Role $state): string => $state->label())
                    ->color(fn (Role $state): string => $state === Role::Admin ? 'primary' : 'gray'),
                IconColumn::make('active')
                    ->label('Aktiv')
                    ->boolean(),
                TextColumn::make('ms_connection_status')
                    ->label('Microsoft 365')
                    ->badge()
                    ->formatStateUsing(fn (ConnectionStatus $state): string => $state->label())
                    ->color(fn (ConnectionStatus $state): string => self::connectionColor($state)),
                TextColumn::make('ms_last_polled_at')
                    ->label('Letzter Abgleich')
                    ->dateTime('d.m.Y H:i', config('intake.display_timezone'))
                    ->placeholder('Nie')
                    ->sortable(),
                TextColumn::make('clickup_connection_status')
                    ->label('ClickUp')
                    ->badge()
                    ->formatStateUsing(fn (ConnectionStatus $state): string => $state->label())
                    ->color(fn (ConnectionStatus $state): string => self::connectionColor($state)),
            ])
            ->recordActions([
                Action::make('changeRole')
                    ->label('Rolle ändern')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->authorize('changeRole')
                    ->fillForm(fn (User $record): array => ['role' => $record->role->value])
                    ->schema([
                        Select::make('role')
                            ->label('Rolle')
                            ->options(collect(Role::cases())->mapWithKeys(fn (Role $role): array => [$role->value => $role->label()])->all())
                            ->required(),
                    ])
                    ->modalSubmitActionLabel('Speichern')
                    ->action(function (User $record, array $data): void {
                        app(UserAdministration::class)->changeRole($record, Role::from($data['role']), self::actor());
                        Notification::make()->title('Rolle geändert.')->success()->send();
                    }),
                Action::make('deactivate')
                    ->label('Deaktivieren')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->authorize('deactivate')
                    ->requiresConfirmation()
                    ->modalHeading('Benutzer deaktivieren')
                    ->modalDescription('Die Anmeldung wird gesperrt und die Verbindungen zu Microsoft 365 und ClickUp werden gelöscht. Seine Mails bleiben im Eingang.')
                    ->modalSubmitActionLabel('Deaktivieren')
                    ->action(function (User $record): void {
                        try {
                            app(UserAdministration::class)->deactivate($record, self::actor());
                        } catch (LockTimeoutException) {
                            Notification::make()->title('Der Benutzer wird gerade abgeglichen. Bitte in einer Minute erneut versuchen.')->warning()->send();

                            return;
                        }

                        Notification::make()->title('Benutzer deaktiviert.')->success()->send();
                    }),
                Action::make('activate')
                    ->label('Aktivieren')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->authorize('activate')
                    ->requiresConfirmation()
                    ->modalHeading('Benutzer aktivieren')
                    ->modalDescription('Der Benutzer kann sich wieder anmelden. Microsoft 365 ist erst nach der nächsten Anmeldung wieder verbunden.')
                    ->modalSubmitActionLabel('Aktivieren')
                    ->action(function (User $record): void {
                        app(UserAdministration::class)->activate($record, self::actor());
                        Notification::make()->title('Benutzer aktiviert.')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
        ];
    }

    private static function connectionColor(ConnectionStatus $status): string
    {
        return match ($status) {
            ConnectionStatus::Connected => 'success',
            ConnectionStatus::Disconnected => 'gray',
            ConnectionStatus::Broken => 'danger',
        };
    }

    private static function actor(): User
    {
        $user = Auth::user();
        assert($user instanceof User);

        return $user;
    }
}
