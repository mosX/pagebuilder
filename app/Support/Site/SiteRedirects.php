<?php

namespace App\Support\Site;

use App\Models\Redirect;
use App\Models\Tenant;
use App\Support\Blocks\BlockRegistry;

/**
 * Redirects of company sites. Paths are relative to the site root ("/old-page"); a path
 * ending in "/*" covers a whole section and carries the rest over ("/blog/*" → "/news/*").
 * They are consulted only for addresses that would otherwise be "not found", so a redirect
 * can never hide a live page.
 */
class SiteRedirects
{
    public const MAX_PER_TENANT = 2000;

    public function __construct(private BlockRegistry $blocks) {}

    /**
     * "/Old-Page/?utm=1" → "/Old-Page"; a full address of an old site gives its path.
     * Null for what cannot be a source path.
     */
    public function normalizeFrom(string $path): ?string
    {
        if (preg_match('#^https?://#i', trim($path))) {
            $path = (string) parse_url(trim($path), PHP_URL_PATH);
        }

        $path = '/'.ltrim(trim(rawurldecode(strtok($path, '?#') ?: '')), '/');
        $path = $path === '/' ? $path : rtrim($path, '/');
        $wildcard = str_ends_with($path, '/*');
        $plain = $wildcard ? substr($path, 0, -2) : $path;

        $valid = $plain !== '/' && $plain !== ''
            && mb_strlen($path) <= 191
            && ! preg_match('/[\s*?#\\\\]/u', $plain)
            && ! str_contains($plain, '..');

        return $valid ? $path : null;
    }

    /**
     * A path on the site or an http(s) address elsewhere.
     */
    public function normalizeTo(string $target): ?string
    {
        $target = trim($target);

        if (str_starts_with($target, '/') && ! str_starts_with($target, '//')) {
            return mb_strlen($target) <= 1024 && ! str_contains($target, '\\') ? $target : null;
        }

        return preg_match('/^https?:\/\//i', $target) && $this->blocks->safeUrl($target) === $target ? $target : null;
    }

    /**
     * The redirect for an address of the site, if any: exact paths first, then the most specific section.
     *
     * @return array{to: string, status: int}|null
     */
    public function match(Tenant $tenant, string $path): ?array
    {
        $path = $this->normalizeFrom($path);

        if ($path === null || str_ends_with($path, '/*')) {
            return null;
        }

        $redirect = Redirect::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('from_path', $path)->first();
        $to = $redirect?->to_path;

        if (! $redirect) {
            // "/blog/2024/post" is covered by "/blog/2024/*", then by "/blog/*".
            $prefixes = [];
            $parts = explode('/', trim($path, '/'));

            for ($i = count($parts) - 1; $i >= 1; $i--) {
                $prefixes[] = '/'.implode('/', array_slice($parts, 0, $i)).'/*';
            }

            $redirect = Redirect::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->whereIn('from_path', $prefixes)
                ->get()
                ->sortByDesc(fn (Redirect $r) => strlen($r->from_path))
                ->first();

            if ($redirect) {
                // "/blog/*" matched "/blog/2024/post": the rest is "/2024/post".
                $rest = substr($path, strlen($redirect->from_path) - 2);
                $to = str_ends_with($redirect->to_path, '/*') ? substr($redirect->to_path, 0, -2).$rest : $redirect->to_path;
            }
        }

        if (! $redirect || ! is_string($to)) {
            return null;
        }

        $redirect->forceFill(['hits' => $redirect->hits + 1, 'last_hit_at' => now()])->saveQuietly();

        return ['to' => $to, 'status' => $redirect->status === 302 ? 302 : 301];
    }

    /**
     * Keeps old links working after an address changed. Earlier redirects to the old address
     * now point at the new one (no chains), and a redirect from the new address is removed
     * (the page lives there now).
     */
    public function moved(Tenant $tenant, string $from, string $to): void
    {
        $from = $this->normalizeFrom($from);
        $to = $this->normalizeTo($to);

        if (! $from || ! $to || $from === $to) {
            return;
        }

        $query = fn () => Redirect::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id);

        $query()->where('from_path', $to)->delete();
        $query()->where('to_path', $from)->update(['to_path' => $to, 'updated_at' => now()]);

        if ($query()->count() >= self::MAX_PER_TENANT) {
            return;
        }

        $redirect = $query()->where('from_path', $from)->first() ?? (new Redirect)->forceFill(['tenant_id' => $tenant->id, 'from_path' => $from]);
        $redirect->forceFill(['to_path' => $to, 'status' => 301, 'is_auto' => true])->save();
    }
}
