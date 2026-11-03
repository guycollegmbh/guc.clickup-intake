<?php

namespace App\Filament\Resources\IntakeRules\Pages;

use App\Filament\Resources\IntakeRules\IntakeRuleResource;
use App\Models\IntakeRule;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageIntakeRules extends ManageRecords
{
    protected static string $resource = IntakeRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Regel erstellen')
                ->modalHeading('Regel erstellen')
                // New rules are checked last, the order is changed by dragging.
                ->mutateDataUsing(fn (array $data): array => IntakeRuleResource::withListLabel([
                    ...$data,
                    'priority' => (int) IntakeRule::query()->max('priority') + 1,
                ])),
        ];
    }
}
