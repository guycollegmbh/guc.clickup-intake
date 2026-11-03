<?php

namespace App\Filament\Resources\IntakeRules;

use App\ClickUp\UserClickUp;
use App\Enums\RuleMatchType;
use App\Filament\Resources\IntakeRules\Pages\ManageIntakeRules;
use App\Models\IntakeRule;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * "Regeln": deterministic pre-selection of list and assignees (R2), admin only (IntakeRulePolicy,
 * R12). Checked by ascending priority, the order is changed by dragging (step 4, RuleMatcher).
 * Lists and members come from the admin's own ClickUp connection in the app workspace, the list
 * label is stored with the rule for display without a ClickUp call.
 */
class IntakeRuleResource extends Resource
{
    protected static ?string $model = IntakeRule::class;

    protected static ?string $slug = 'regeln';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Regeln';

    protected static ?string $modelLabel = 'Regel';

    protected static ?string $pluralModelLabel = 'Regeln';

    protected static ?int $navigationSort = 3;

    protected static bool $isGloballySearchable = false;

    /**
     * Domain without scheme or path, optionally with a leading "@" (RuleMatcher ignores it).
     */
    public const DOMAIN_PATTERN = '/^@?(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Select::make('match_type')
                    ->label('Bedingung')
                    ->options(collect(RuleMatchType::cases())->mapWithKeys(fn (RuleMatchType $type): array => [$type->value => $type->label()])->all())
                    ->required()
                    ->live(),
                TextInput::make('match_value')
                    ->label('Wert')
                    ->required()
                    ->maxLength(255)
                    ->rules(fn (Get $get): array => match (self::matchType($get('match_type'))) {
                        RuleMatchType::FromDomain => ['regex:'.self::DOMAIN_PATTERN],
                        RuleMatchType::FromEmail => ['email'],
                        default => [],
                    })
                    ->validationMessages([
                        'regex' => 'Bitte eine Domain wie beispiel.ch eingeben.',
                        'email' => 'Bitte eine E-Mail-Adresse eingeben.',
                    ])
                    ->helperText(fn (Get $get): ?string => match (self::matchType($get('match_type'))) {
                        RuleMatchType::FromDomain => 'Genau diese Domain, ohne Subdomains.',
                        RuleMatchType::FromEmail => 'Genau diese Absender-Adresse.',
                        RuleMatchType::SubjectContains => 'Teil des Betreffs, ohne Gross- und Kleinschreibung.',
                        null => null,
                    }),
                Callout::make('Verbinde dein ClickUp-Konto, um Listen und Zuständige zu wählen.')
                    ->warning()
                    ->controlActions([
                        Action::make('connectClickUp')
                            ->label('Mit ClickUp verbinden')
                            ->url(fn (): string => route('clickup.connect')),
                    ])
                    ->visible(fn (): bool => ! app(UserClickUp::class)->isConnected(self::admin())),
                Select::make('clickup_list_id')
                    ->label('Ziel-List')
                    ->options(fn (): array => self::options('lists'))
                    ->searchable()
                    ->required()
                    ->visible(fn (): bool => app(UserClickUp::class)->isConnected(self::admin())),
                Select::make('assignee_ids')
                    ->label('Zuständig')
                    ->multiple()
                    ->options(fn (): array => self::options('members'))
                    ->searchable()
                    ->visible(fn (): bool => app(UserClickUp::class)->isConnected(self::admin())),
                Toggle::make('active')
                    ->label('Aktiv')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('priority')
            ->defaultSort('priority')
            ->columns([
                TextColumn::make('priority')
                    ->label('Reihenfolge')
                    ->numeric(),
                TextColumn::make('match_type')
                    ->label('Bedingung')
                    ->formatStateUsing(fn (RuleMatchType $state): string => $state->label()),
                TextColumn::make('match_value')
                    ->label('Wert')
                    ->searchable(),
                TextColumn::make('clickup_list_label')
                    ->label('Ziel-List')
                    ->wrap(),
                TextColumn::make('assignee_ids')
                    ->label('Zuständig')
                    ->state(fn (IntakeRule $record): array => self::assigneeNames($record))
                    ->listWithLineBreaks()
                    ->placeholder('Keine'),
                IconColumn::make('active')
                    ->label('Aktiv')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalHeading('Regel bearbeiten')
                    ->mutateDataUsing(fn (array $data, IntakeRule $record): array => self::withListLabel($data, $record)),
                DeleteAction::make()
                    ->modalHeading('Regel löschen'),
            ])
            ->emptyStateHeading('Keine Regeln')
            ->emptyStateDescription('Regeln belegen Ziel-List und Zuständige anhand von Absender oder Betreff vor.');
    }

    /**
     * Stores the label "Space / Folder / List" with the rule. If the admin's hierarchy does not
     * know the list (any more), the stored label of an unchanged list stays.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withListLabel(array $data, ?IntakeRule $rule = null): array
    {
        $listId = (string) ($data['clickup_list_id'] ?? '');
        $label = self::options('lists')[$listId] ?? null;

        if ($label === null && $rule !== null && $rule->clickup_list_id === $listId) {
            $label = $rule->clickup_list_label;
        }

        return [
            ...$data,
            'clickup_list_label' => $label ?? $listId,
            'assignee_ids' => array_values(array_map('intval', array_filter((array) ($data['assignee_ids'] ?? []), 'is_numeric'))),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageIntakeRules::route('/'),
        ];
    }

    /**
     * @return list<string>
     */
    private static function assigneeNames(IntakeRule $rule): array
    {
        $members = self::options('members');

        return array_values(array_map(
            fn (mixed $id): string => $members[(int) $id] ?? '#'.$id,
            (array) ($rule->assignee_ids ?? []),
        ));
    }

    /**
     * Lists or members of the admin, empty without connection or if ClickUp fails.
     *
     * @param  'lists'|'members'  $kind
     * @return array<int|string, string>
     */
    private static function options(string $kind): array
    {
        $admin = self::admin();
        $clickUp = app(UserClickUp::class);

        if (! $clickUp->isConnected($admin)) {
            return [];
        }

        try {
            return $kind === 'lists' ? $clickUp->lists($admin) : $clickUp->members($admin);
        } catch (Throwable) {
            return [];
        }
    }

    private static function matchType(mixed $value): ?RuleMatchType
    {
        return $value instanceof RuleMatchType ? $value : RuleMatchType::tryFrom((string) $value);
    }

    private static function admin(): User
    {
        $user = Auth::user();
        assert($user instanceof User);

        return $user;
    }
}
