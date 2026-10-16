<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;
use Illuminate\Validation\Rule;

/**
 * Company slugs double as subdomains of the platform: {slug}.{site_domain}.
 */
class Subdomains
{
    /** A single DNS label: lowercase letters, digits and inner hyphens, at most 63 characters. */
    public const PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    public static function domain(): string
    {
        $domain = config('tenancy.site_domain');

        return is_string($domain) ? strtolower($domain) : 'pagebuilder.test';
    }

    public static function isReserved(string $slug): bool
    {
        return in_array($slug, self::reserved(), true);
    }

    public static function isValid(string $slug): bool
    {
        return (bool) preg_match(self::PATTERN, $slug) && ! self::isReserved($slug);
    }

    /**
     * Validation rules for a company slug (uniqueness is added by the caller).
     *
     * @return list<mixed>
     */
    public static function rules(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:63', 'regex:'.self::PATTERN, Rule::notIn(self::reserved())];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $attribute = 'slug'): array
    {
        return [
            "{$attribute}.regex" => 'Адрес: латинские буквы в нижнем регистре, цифры и дефисы (не в начале и не в конце), до 63 символов.',
            "{$attribute}.not_in" => 'Этот адрес зарезервирован платформой.',
        ];
    }

    public static function siteUrl(Tenant $tenant): string
    {
        $scheme = config('tenancy.site_scheme');

        return sprintf('%s://%s.%s', is_string($scheme) ? $scheme : 'https', $tenant->slug, self::domain());
    }

    /**
     * @return list<string>
     */
    private static function reserved(): array
    {
        $reserved = config('tenancy.reserved_subdomains');

        return is_array($reserved) ? array_values(array_filter($reserved, 'is_string')) : [];
    }
}
