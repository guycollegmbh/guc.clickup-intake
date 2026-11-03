<?php

use App\Intake\TaskName;

it('removes reply and forward prefixes for the task name', function (?string $subject, string $expected) {
    expect(TaskName::fromSubject($subject))->toBe($expected);
})->with([
    'plain' => ['Offerte Website', 'Offerte Website'],
    're' => ['Re: Offerte', 'Offerte'],
    'aw' => ['AW: Offerte', 'Offerte'],
    'wg' => ['WG: Offerte', 'Offerte'],
    'fwd' => ['Fwd: Offerte', 'Offerte'],
    'fw' => ['FW: Offerte', 'Offerte'],
    'repeated' => ['Re: Re: Re: Offerte', 'Offerte'],
    'mixed' => ['AW: WG: Fwd: re: Offerte', 'Offerte'],
    'case' => ['rE: aW: Offerte', 'Offerte'],
    'spaces' => ['  Re :   AW:Offerte  ', 'Offerte'],
    'inner whitespace' => ["Offerte \t  Website\nNeu", 'Offerte Website Neu'],
    'prefix inside' => ['Offerte AW: Website', 'Offerte AW: Website'],
    'word starting like a prefix' => ['Rechnung: März', 'Rechnung: März'],
    'prefix without colon' => ['Re Offerte', 'Re Offerte'],
    'empty' => ['', '(ohne Betreff)'],
    'null' => [null, '(ohne Betreff)'],
    'only prefixes' => ['Re: AW:', '(ohne Betreff)'],
    'only whitespace' => ['   ', '(ohne Betreff)'],
]);

it('strips French and other forward prefixes, also with a space before the colon', function (string $subject) {
    expect(TaskName::fromSubject($subject))->toBe('Budget 2027');
})->with(['Tr : Budget 2027', 'TR: Budget 2027', 'Tr : RE: AW:Budget 2027', 'Antw: Budget 2027', 'SV: Budget 2027', 'VS: Budget 2027', 'RV: Budget 2027']);
