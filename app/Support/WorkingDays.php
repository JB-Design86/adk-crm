<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Arbeitstage: Montag bis Freitag ohne bundeseinheitliche Feiertage und
 * ohne die Feiertage des Bundeslands aus config('adk.holiday_region').
 */
class WorkingDays
{
    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    public static function add(CarbonInterface $date, int $days): CarbonImmutable
    {
        $current = CarbonImmutable::instance($date)->startOfDay();

        while ($days > 0) {
            $current = $current->addDay();

            if (self::isWorkingDay($current)) {
                $days--;
            }
        }

        return $current;
    }

    public static function isWorkingDay(CarbonInterface $date): bool
    {
        return ! $date->isWeekend() && ! self::isHoliday($date);
    }

    public static function isHoliday(CarbonInterface $date): bool
    {
        return array_key_exists($date->format('Y-m-d'), self::holidays($date->year));
    }

    /** @return array<string, string> Datum => Bezeichnung */
    public static function holidays(int $year): array
    {
        $region = config('adk.holiday_region', 'RP');
        $cacheKey = $year.$region;

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $easter = self::easterSunday($year);

        $holidays = [
            "{$year}-01-01" => 'Neujahr',
            $easter->subDays(2)->format('Y-m-d') => 'Karfreitag',
            $easter->addDay()->format('Y-m-d') => 'Ostermontag',
            "{$year}-05-01" => 'Tag der Arbeit',
            $easter->addDays(39)->format('Y-m-d') => 'Christi Himmelfahrt',
            $easter->addDays(50)->format('Y-m-d') => 'Pfingstmontag',
            "{$year}-10-03" => 'Tag der Deutschen Einheit',
            "{$year}-12-25" => '1. Weihnachtstag',
            "{$year}-12-26" => '2. Weihnachtstag',
        ];

        if ($region === 'RP') {
            $holidays[$easter->addDays(60)->format('Y-m-d')] = 'Fronleichnam';
            $holidays["{$year}-11-01"] = 'Allerheiligen';
        }

        return self::$cache[$cacheKey] = $holidays;
    }

    /** Ostersonntag nach der Gaußschen Osterformel (gregorianischer Kalender). */
    public static function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day);
    }
}
