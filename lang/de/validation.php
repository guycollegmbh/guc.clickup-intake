<?php

// German messages for the validation rules used in the panel (R15). Filament shows them below the
// field, so they do without the field name (Filament would write it in lower case). Rules without
// an entry fall back to the key, add them when a form starts using them.

return [
    'array' => 'Die Auswahl ist ungültig.',
    'date' => 'Bitte ein gültiges Datum eingeben.',
    'email' => 'Bitte eine gültige E-Mail-Adresse eingeben.',
    'in' => 'Der gewählte Wert ist ungültig.',
    'integer' => 'Bitte eine ganze Zahl eingeben.',
    'max' => [
        'array' => 'Höchstens :max Einträge.',
        'string' => 'Höchstens :max Zeichen.',
    ],
    'regex' => 'Das Format ist ungültig.',
    'required' => 'Dieses Feld ist erforderlich.',
    'string' => 'Bitte einen Text eingeben.',
];
