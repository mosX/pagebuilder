<?php

namespace App\Support\Site;

use App\Models\Tenant;
use Illuminate\Database\Query\Builder;

/**
 * Scheduled publishing: content with a publication date is visible on the site from that
 * moment on. Empty dates mean "right away". Dates are entered in the company's local time
 * (its time zone setting) and stored as "Y-m-d[ H:i:s]" strings, so they are compared with
 * the current local date-time string of that company.
 */
class Scheduling
{
    /** The system field of pages with their publication time. */
    public const PAGES_FIELD = 'publish_at';

    public const DEFAULT_TIMEZONE = 'Europe/Moscow';

    /** @var array<int, string> */
    private static array $timezones = [];

    public static function released(Builder $query, string $column, int $tenantId): Builder
    {
        $now = self::now($tenantId);

        return $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, '<=', $now));
    }

    /**
     * The company's current local date and time, "Y-m-d H:i:s".
     */
    public static function now(int $tenantId): string
    {
        return now(self::timezone($tenantId))->toDateTimeString();
    }

    public static function timezone(int $tenantId): string
    {
        if (! isset(self::$timezones[$tenantId])) {
            $timezone = Tenant::query()->whereKey($tenantId)->value('timezone');
            self::$timezones[$tenantId] = is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : self::DEFAULT_TIMEZONE;
        }

        return self::$timezones[$tenantId];
    }

    /**
     * Forgets the remembered time zones (after a company changed its setting, and between tests).
     */
    public static function forget(): void
    {
        self::$timezones = [];
    }
}
