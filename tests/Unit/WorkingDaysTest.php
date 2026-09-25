<?php

use App\Support\WorkingDays;
use Carbon\CarbonImmutable;

it('berechnet Ostersonntag', function (int $year, string $expected) {
    expect(WorkingDays::easterSunday($year)->format('Y-m-d'))->toBe($expected);
})->with([
    [2025, '2025-04-20'],
    [2026, '2026-04-05'],
    [2027, '2027-03-28'],
]);

it('kennt die Feiertage in Rheinland-Pfalz', function () {
    $holidays = WorkingDays::holidays(2026);

    expect($holidays)->toHaveKeys([
        '2026-01-01', '2026-04-03', '2026-04-06', '2026-05-01', '2026-05-14',
        '2026-05-25', '2026-06-04', '2026-10-03', '2026-11-01', '2026-12-25', '2026-12-26',
    ]);
    // Reformationstag ist in Rheinland-Pfalz kein Feiertag.
    expect($holidays)->not->toHaveKey('2026-10-31');
});

it('zählt zwei Arbeitstage über das Wochenende', function () {
    // Donnerstag, 24.09.2026 + 2 Arbeitstage = Montag, 28.09.2026
    expect(WorkingDays::add(CarbonImmutable::parse('2026-09-24'), 2)->format('Y-m-d'))->toBe('2026-09-28');
});

it('überspringt Feiertage', function () {
    // Donnerstag, 01.10.2026 + 2 Arbeitstage: Fr 02.10., dann Wochenende → Montag 05.10.
    expect(WorkingDays::add(CarbonImmutable::parse('2026-10-01'), 2)->format('Y-m-d'))->toBe('2026-10-05');
    // Mittwoch, 03.06.2026 + 1: Fronleichnam (Do 04.06.) → Freitag 05.06.
    expect(WorkingDays::add(CarbonImmutable::parse('2026-06-03'), 1)->format('Y-m-d'))->toBe('2026-06-05');
    // Mittwoch, 23.12.2026 + 5: 24.12., (25./26. frei, Wochenende), 28., 29., 30., 31.12.
    expect(WorkingDays::add(CarbonImmutable::parse('2026-12-23'), 5)->format('Y-m-d'))->toBe('2026-12-31');
});

it('zählt fünf Arbeitstage', function () {
    // Freitag, 25.09.2026 + 5 = Freitag, 02.10.2026
    expect(WorkingDays::add(CarbonImmutable::parse('2026-09-25'), 5)->format('Y-m-d'))->toBe('2026-10-02');
});
