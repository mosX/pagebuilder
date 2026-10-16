<?php

namespace App\Support\Site;

use App\Models\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Public URLs of records of collections published as site sections:
 * {baseUrl}/{site_path}/{value of site_slug_field}.
 */
class RecordUrls
{
    /**
     * @param  array<string, mixed>  $record
     */
    public function record(Collection $collection, array $record, string $baseUrl = ''): ?string
    {
        if (! $collection->site_path) {
            return null;
        }

        $slug = $collection->site_slug_field && $collection->site_slug_field !== 'id'
            ? ($record[$collection->site_slug_field] ?? null)
            : ($record['id'] ?? null);

        return is_scalar($slug) && (string) $slug !== ''
            ? "{$baseUrl}/{$collection->site_path}/".rawurlencode((string) $slug)
            : null;
    }

    public function recordById(Collection $collection, int $id, string $baseUrl = ''): ?string
    {
        if (! $collection->site_path || $id <= 0) {
            return null;
        }

        $field = $collection->site_slug_field;
        $slug = $field && $field !== 'id'
            ? DB::table($collection->table_name)->where('id', $id)->value($field)
            : $id;

        return $this->record($collection, ['id' => $id, (string) $field => $slug], $baseUrl);
    }
}
