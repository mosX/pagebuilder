<?php

namespace App\Http\Resources;

use App\Models\CollectionField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property CollectionField $resource
 */
class CollectionFieldResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $field = $this->resource;
        $related = $field->relatedCollection;

        return [
            'id' => $field->id,
            'name' => $field->name,
            'label' => $field->label,
            'type' => $field->type->value,
            'type_label' => $field->type->label(),
            'options' => (object) ($field->options ?? []),
            'is_required' => $field->is_required,
            'is_unique' => $field->is_unique,
            'is_system' => $field->is_system,
            'sort_order' => $field->sort_order,
            'related_collection' => $related ? $related->only(['id', 'name', 'label']) : null,
        ];
    }
}
