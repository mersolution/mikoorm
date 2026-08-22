<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Core\Helpers;

/**
 * DateHelper - Date and time utilities with Turkish locale support
 */
class DateHelper
{
    private static array $turkishMonths = [
        1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan',
        5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos',
        9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'
    ];

    private static array $turkishDays = [
        'Monday' => 'Pazartesi',
        'Tuesday' => 'Salı',
        'Wednesday' => 'Çarşamba',
        'Thursday' => 'Perşembe',
        'Friday' => 'Cuma',
        'Saturday' => 'Cumartesi',
        'Sunday' => 'Pazar'
    ];

    /**
     * Format date in Turkish (25 Ocak 2026)
     */
    public static function toTurkish(string|\DateTime $date): string
    {
        $dt = $date instanceof \DateTime ? $date : new \DateTime($date);
        $day = $dt->format('j');
        $month = self::$turkishMonths[(int)$dt->format('n')];
        $year = $dt->format('Y');

        return "{$day} {$month} {$year}";
    }

    /**
     * Format datetime in Turkish (25 Ocak 2026 03:30)
     */
    public static function toTurkishDateTime(string|\DateTime $date): string
    {
        $dt = $date instanceof \DateTime ? $date : new \DateTime($date);
        return self::toTurkish($dt) . ' ' . $dt->format('H:i');
    }

    /**
     * Get Turkish day name
     */
    public static function turkishDayName(string|\DateTime $date): string
    {
        $dt = $date instanceof \DateTime ? $date : new \DateTime($date);
        $englishDay = $dt->format('l');
        return self::$turkishDays[$englishDay] ?? $englishDay;
    }

    /**
     * Get Turkish month name
     */
    public static function turkishMonthName(int $month): string
    {
        return self::$turkishMonths[$month] ?? '';
    }

    /**
     * Human readable time difference (2 saat önce, 3 gün önce)
     */
    public static function diffForHumans(string|\DateTime $date): string
    {
        $dt = $date instanceof \DateTime ? $date : new \DateTime($date);
        $now = new \DateTime();
        $diff = $now->diff($dt);

        $isPast = $dt < $now;
        $suffix = $isPast ? ' önce' : ' sonra';

        if ($diff->y > 0) {
            return $diff->y . ' yıl' . $suffix;
        }
        if ($diff->m > 0) {
            return $diff->m . ' ay' . $suffix;
        }
        if ($diff->d > 0) {
            if ($diff->d === 1) {
                return $isPast ? 'dün' : 'yarın';
            }
            return $diff->d . ' gün' . $suffix;
        }
        if ($diff->h > 0) {
            return $diff->h . ' saat' . $suffix;
        }
        if ($diff->i > 0) {
            return $diff->i . ' dakika' . $suffix;
        }

        return 'az önce';
    }

    /**
     * Check if date is today
     */
    public static function isToday(string|\DateTime $date): bool
    {
        $dt = $date instanceof \DateTime ? $date : new \DateTime($date);
        return $dt->format('Y-m-d') === (new \DateTime())->format('Y-m-d');
    }

    /**
     * Check if date is yesterday
     */
    public static function isYesterday(string|\DateTime $date): bool
    {
        $dt = $date instanceof \DateTime ? $date : new \DateTime($date);
        $yesterday = (new \DateTime())->modify('-1 day');
        return $dt->format('Y-m-d') === $yesterday->format('Y-m-d');
    }

    /**
     * Check if date is this week
     */
    public static function isThisWeek(string|\DateTime $date): bool
    {
        $dt = $date instanceof \DateTime ? $date : new \DateTime($date);
        $now = new \DateTime();
        return $dt->format('oW') === $now->format('oW');
    }

    /**
     * Check if date is this month
     */
    public static function isThisMonth(string|\DateTime $date): bool
    {
        $dt = $date instanceof \DateTime ? $date : new \DateTime($date);
        $now = new \DateTime();
        return $dt->format('Y-m') === $now->format('Y-m');
    }

    /**
     * Get start of day
     */
    public static function startOfDay(string|\DateTime $date = null): \DateTime
    {
        $dt = $date instanceof \DateTime ? clone $date : new \DateTime($date ?? 'now');
        return $dt->setTime(0, 0, 0);
    }

    /**
     * Get end of day
     */
    public static function endOfDay(string|\DateTime $date = null): \DateTime
    {
        $dt = $date instanceof \DateTime ? clone $date : new \DateTime($date ?? 'now');
        return $dt->setTime(23, 59, 59);
    }

    /**
     * Get start of month
     */
    public static function startOfMonth(string|\DateTime $date = null): \DateTime
    {
        $dt = $date instanceof \DateTime ? clone $date : new \DateTime($date ?? 'now');
        return $dt->modify('first day of this month')->setTime(0, 0, 0);
    }

    /**
     * Get end of month
     */
    public static function endOfMonth(string|\DateTime $date = null): \DateTime
    {
        $dt = $date instanceof \DateTime ? clone $date : new \DateTime($date ?? 'now');
        return $dt->modify('last day of this month')->setTime(23, 59, 59);
    }

    /**
     * Get date range for period
     */
    public static function getDateRange(string $period): array
    {
        $now = new \DateTime();

        return match($period) {
            'today' => [
                'start' => self::startOfDay(),
                'end' => self::endOfDay()
            ],
            'yesterday' => [
                'start' => self::startOfDay((new \DateTime())->modify('-1 day')),
                'end' => self::endOfDay((new \DateTime())->modify('-1 day'))
            ],
            'this_week' => [
                'start' => (new \DateTime())->modify('monday this week')->setTime(0, 0, 0),
                'end' => (new \DateTime())->modify('sunday this week')->setTime(23, 59, 59)
            ],
            'last_week' => [
                'start' => (new \DateTime())->modify('monday last week')->setTime(0, 0, 0),
                'end' => (new \DateTime())->modify('sunday last week')->setTime(23, 59, 59)
            ],
            'this_month' => [
                'start' => self::startOfMonth(),
                'end' => self::endOfMonth()
            ],
            'last_month' => [
                'start' => self::startOfMonth((new \DateTime())->modify('-1 month')),
                'end' => self::endOfMonth((new \DateTime())->modify('-1 month'))
            ],
            'this_year' => [
                'start' => (new \DateTime('first day of January'))->setTime(0, 0, 0),
                'end' => (new \DateTime('last day of December'))->setTime(23, 59, 59)
            ],
            default => [
                'start' => self::startOfDay(),
                'end' => self::endOfDay()
            ]
        };
    }

    /**
     * Format for SQL
     */
    public static function toSql(string|\DateTime $date): string
    {
        $dt = $date instanceof \DateTime ? $date : new \DateTime($date);
        return $dt->format('Y-m-d H:i:s');
    }

    /**
     * Format for SQL date only
     */
    public static function toSqlDate(string|\DateTime $date): string
    {
        $dt = $date instanceof \DateTime ? $date : new \DateTime($date);
        return $dt->format('Y-m-d');
    }
}
