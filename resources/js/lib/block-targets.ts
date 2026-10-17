import type { RouteLocationRaw } from 'vue-router';

/**
 * Where a block constructor target is edited in the admin:
 * field:{collection}:{record}:{field}[@locale], template:{collection}, pattern:{id}, layout:{part}.
 */
export function targetLocation(target: string): RouteLocationRaw | null {
    const [path, locale] = target.split('@');
    const [type, ...parts] = path.split(':');

    switch (type) {
        case 'field':
            return {
                name: 'blocks.editor',
                params: { name: parts[0], id: parts[1], field: parts[2] },
                query: locale ? { locale } : {},
            };
        case 'template':
            return { name: 'collections.template', params: { name: parts[0] } };
        case 'pattern':
            return { name: 'patterns.edit', params: { id: parts[0] } };
        case 'layout':
            return {
                name: 'site.layout',
                params: { part: parts[0].replace('_', '-') },
            };
        default:
            return null;
    }
}
