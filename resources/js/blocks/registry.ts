import type { Component } from 'vue';

/**
 * Blocks are code: every folder in resources/js/blocks has a block.json (the schema,
 * also read by Laravel to validate saved trees) and a Block.vue that renders it.
 * Pages store data only — a tree of { id, type, props, children }.
 */

export type PropSchema = {
    type:
        | 'text'
        | 'textarea'
        | 'richtext'
        | 'number'
        | 'boolean'
        | 'select'
        | 'color'
        | 'url'
        | 'image'
        | 'collection'
        | 'field'
        | 'fields'
        | 'record_field'
        | 'pattern'
        | 'list';
    label: string;
    default?: unknown;
    options?: { value: string; label: string }[];
    min?: number;
    /** Upper bound of a number, or the most items of a list. */
    max?: number;
    step?: number;
    /** For `field`: the sibling prop holding the collection name. */
    of?: string;
    /** For `field`: only fields of these types. */
    fieldTypes?: string[];
    help?: string;
    /** For `list`: the props of one item. */
    item?: Record<string, PropSchema>;
    /** For `list`: the item prop shown as the item's title in the editor. */
    itemLabel?: string;
};

export type BlockSchema = {
    name: string;
    label: string;
    category: string;
    icon: string;
    description?: string;
    /** Container blocks hold nested blocks. */
    children?: boolean;
    /** Blocks whose content comes from company data, resolved by the server. */
    data?: string;
    /** Service blocks the server places on generated pages (search, account); not in the palette. */
    hidden?: boolean;
    props: Record<string, PropSchema>;
};

export type BlockDefinition = BlockSchema & { component: Component };

export type Device = 'desktop' | 'tablet' | 'mobile';

export const devices: { key: Device; label: string }[] = [
    { key: 'mobile', label: 'Телефон' },
    { key: 'tablet', label: 'Планшет' },
    { key: 'desktop', label: 'Компьютер' },
];

/**
 * Container-query classes that hide a block per device. Pages and the editor canvas are
 * `@container`s, so the editor's device preview behaves exactly like real screens.
 * Literal strings, so Tailwind generates them.
 */
export const hideClasses: Record<Device, string> = {
    mobile: '@max-3xl:hidden',
    tablet: '@3xl:@max-5xl:hidden',
    desktop: '@5xl:hidden',
};

export type BlockNode = {
    id: string;
    type: string;
    props: Record<string, unknown>;
    children?: BlockNode[];
    /** Devices the block is hidden on. */
    hide?: Device[];
    /** Server-resolved data of data blocks (public site only, never saved). */
    data?: unknown;
};

export const categories = [
    { key: 'layout', label: 'Структура' },
    { key: 'text', label: 'Текст' },
    { key: 'media', label: 'Медиа' },
    { key: 'data', label: 'Данные' },
    { key: 'site', label: 'Сайт' },
    /** Only offered while editing a record page template. */
    { key: 'record', label: 'Запись' },
];

const schemas = import.meta.glob<BlockSchema>('./*/block.json', {
    eager: true,
    import: 'default',
});

const components = import.meta.glob<Component>('./*/Block.vue', {
    eager: true,
    import: 'default',
});

export const blocks: Record<string, BlockDefinition> = Object.fromEntries(
    Object.entries(schemas).flatMap(([path, schema]) => {
        const component = components[path.replace('block.json', 'Block.vue')];

        return component ? [[schema.name, { ...schema, component }]] : [];
    }),
);

export function newId(): string {
    const alphabet =
        'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    return Array.from(crypto.getRandomValues(new Uint8Array(10)))
        .map((byte) => alphabet[byte % alphabet.length])
        .join('');
}

function defaults(definition: BlockSchema): Record<string, unknown> {
    return Object.fromEntries(
        Object.entries(definition.props).map(([key, prop]) => [
            key,
            structuredClone(prop.default ?? null),
        ]),
    );
}

export function createNode(type: string): BlockNode {
    const definition = blocks[type];

    return {
        id: newId(),
        type,
        props: defaults(definition),
        ...(definition.children ? { children: [] } : {}),
    };
}

/**
 * Brings a stored tree into editor shape: unknown blocks are dropped,
 * missing props get defaults and containers always have a children array.
 */
export function normalizeTree(tree: unknown): BlockNode[] {
    if (!Array.isArray(tree)) {
        return [];
    }

    return tree.flatMap((raw: Partial<BlockNode>) => {
        const definition =
            raw && typeof raw.type === 'string' ? blocks[raw.type] : undefined;

        if (!definition) {
            return [];
        }

        const node: BlockNode = {
            id: typeof raw.id === 'string' ? raw.id : newId(),
            type: definition.name,
            props: { ...defaults(definition), ...raw.props },
        };

        if (definition.children) {
            node.children = normalizeTree(raw.children);
        }

        if (Array.isArray(raw.hide) && raw.hide.length) {
            node.hide = raw.hide.filter((d): d is Device => d in hideClasses);
        }

        if (raw.data !== undefined) {
            node.data = raw.data;
        }

        return [node];
    });
}

/**
 * Deep copy with fresh ids (for "duplicate").
 */
export function cloneNode(node: BlockNode): BlockNode {
    return {
        id: newId(),
        type: node.type,
        props: structuredClone(node.props),
        ...(node.hide?.length ? { hide: [...node.hide] } : {}),
        ...(node.children ? { children: node.children.map(cloneNode) } : {}),
    };
}

/**
 * The list that contains the node with the given id, and its index there.
 */
export function locate(
    tree: BlockNode[],
    id: string,
): { list: BlockNode[]; index: number } | null {
    const index = tree.findIndex((node) => node.id === id);

    if (index !== -1) {
        return { list: tree, index };
    }

    for (const node of tree) {
        const found = node.children ? locate(node.children, id) : null;

        if (found) {
            return found;
        }
    }

    return null;
}

/**
 * The tree as it is saved: no resolved data.
 */
export function serializeTree(tree: BlockNode[]): BlockNode[] {
    return tree.map(({ id, type, props, children, hide }) => ({
        id,
        type,
        props,
        ...(hide?.length ? { hide } : {}),
        ...(children ? { children: serializeTree(children) } : {}),
    }));
}

export function visibilityClass(node: BlockNode): string {
    return (node.hide ?? []).map((device) => hideClasses[device]).join(' ');
}
