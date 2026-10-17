<?php

namespace App\Http\Resources;

use App\Models\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Collection $resource
 */
class CollectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $collection = $this->resource;

        return [
            'id' => $collection->id,
            'name' => $collection->name,
            'label' => $collection->label,
            'description' => $collection->description,
            'table_name' => $collection->table_name,
            'display_field' => $collection->display_field,
            'kind' => $collection->kind,
            'site' => [
                'path' => $collection->site_path,
                'slug_field' => $collection->site_slug_field,
                'published_field' => $collection->site_published_field,
                'publish_at_field' => $collection->site_publish_at_field,
                'url' => $collection->site_path ? $collection->tenant->siteUrl().'/'.$collection->site_path : null,
            ],
            'site_template' => $this->when($request->routeIs('api.collections.show'), fn () => $collection->site_template ?? []),
            'records_count' => $this->whenHas('records_count'),
            'fields' => CollectionFieldResource::collection($this->whenLoaded('fields')),
        ];
    }
}
