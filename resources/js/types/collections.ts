import type { BlockNode } from '@/blocks/registry';

export type FieldTypeValue =
    | 'string'
    | 'text'
    | 'email'
    | 'url'
    | 'image'
    | 'file'
    | 'integer'
    | 'decimal'
    | 'boolean'
    | 'date'
    | 'datetime'
    | 'select'
    | 'belongs_to'
    | 'belongs_to_many'
    | 'blocks';

export type FieldTypeOption = {
    value: FieldTypeValue;
    label: string;
    group: string;
    supports_unique: boolean;
    is_relation: boolean;
};

export type CollectionRef = {
    id: number;
    name: string;
    label: string;
};

export type CollectionField = {
    id: number;
    name: string;
    label: string;
    type: FieldTypeValue;
    type_label: string;
    options: { choices?: string[]; scale?: number };
    is_required: boolean;
    is_unique: boolean;
    is_system: boolean;
    sort_order: number;
    related_collection: CollectionRef | null;
};

export type CollectionSchema = CollectionRef & {
    description: string | null;
    table_name: string;
    display_field: string | null;
    kind: string | null;
    /** Publication as a site section: /{path}/{value of slug_field}. */
    site: {
        path: string | null;
        slug_field: string | null;
        published_field: string | null;
        /** Date field: records appear on the site from this date. */
        publish_at_field: string | null;
        url: string | null;
    };
    site_template?: BlockNode[];
    records_count?: number;
    fields: CollectionField[];
};

export type RelationValue = { id: number; label: string };

export type RecordValue =
    | string
    | number
    | boolean
    | null
    | RelationValue
    | RelationValue[]
    | BlockNode[];

export type CollectionRecord = {
    id: number;
    created_at: string;
    updated_at: string;
    [field: string]: RecordValue;
};

export type MediaItem = {
    id: number;
    url: string;
    name: string;
    mime_type: string;
    kind: 'image' | 'document';
    /** File extension, e.g. "pdf". */
    extension: string;
    size: number;
    width: number | null;
    height: number | null;
    alt: string | null;
    created_at: string | null;
};
