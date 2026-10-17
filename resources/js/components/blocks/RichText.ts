import { defineComponent, h } from 'vue';
import type { PropType, VNode, VNodeArrayChildren } from 'vue';

/**
 * Renders formatted text (a Tiptap/ProseMirror JSON document) as elements.
 * Never uses innerHTML: the document is data, so it cannot inject markup.
 * Plain strings (older content) render as paragraphs.
 */
export type RichMark = { type: string; attrs?: { href?: string } };

export type RichNode = {
    type: string;
    text?: string;
    marks?: RichMark[];
    attrs?: { level?: number; start?: number };
    content?: RichNode[];
};

const SAFE_HREF = /^(https?:\/\/|mailto:|tel:|#|\/(?!\/))/i;

const classes: Record<string, string> = {
    bulletList: 'list-disc pl-6 flex flex-col gap-1',
    orderedList: 'list-decimal pl-6 flex flex-col gap-1',
    blockquote: 'border-l-4 border-gray-300 pl-4 italic text-gray-600',
    horizontalRule: 'border-gray-200',
    h2: 'text-2xl font-bold text-gray-900',
    h3: 'text-xl font-semibold text-gray-900',
    h4: 'text-lg font-semibold text-gray-900',
};

const tags: Record<string, string> = {
    paragraph: 'p',
    bulletList: 'ul',
    orderedList: 'ol',
    listItem: 'li',
    blockquote: 'blockquote',
    hardBreak: 'br',
    horizontalRule: 'hr',
};

function renderText(node: RichNode): VNode | string {
    let result: VNode | string = node.text ?? '';

    for (const mark of node.marks ?? []) {
        switch (mark.type) {
            case 'bold':
                result = h('strong', null, [result]);
                break;
            case 'italic':
                result = h('em', null, [result]);
                break;
            case 'strike':
                result = h('s', null, [result]);
                break;
            case 'underline':
                result = h('u', null, [result]);
                break;
            case 'code':
                result = h(
                    'code',
                    {
                        class: 'rounded bg-gray-100 px-1 font-mono text-[0.9em]',
                    },
                    [result],
                );
                break;
            case 'link': {
                const href = mark.attrs?.href ?? '';

                if (SAFE_HREF.test(href)) {
                    result = h(
                        'a',
                        {
                            href,
                            rel: 'noopener nofollow',
                            class: 'text-blue-600 underline hover:text-blue-800',
                        },
                        [result],
                    );
                }
                break;
            }
        }
    }

    return result;
}

function renderNodes(nodes: RichNode[] = []): VNodeArrayChildren {
    return nodes.map((node) => {
        if (node.type === 'text') {
            return renderText(node);
        }

        if (node.type === 'heading') {
            const level = Math.min(4, Math.max(2, node.attrs?.level ?? 2));

            return h(
                `h${level}`,
                { class: classes[`h${level}`] },
                renderNodes(node.content),
            );
        }

        const tag = tags[node.type];

        if (!tag) {
            return null;
        }

        const attrs: Record<string, unknown> = { class: classes[node.type] };

        if (node.type === 'orderedList' && node.attrs?.start) {
            attrs.start = node.attrs.start;
        }

        return tag === 'br' || tag === 'hr'
            ? h(tag, attrs)
            : h(tag, attrs, renderNodes(node.content));
    });
}

export function toDoc(value: unknown): RichNode {
    if (typeof value === 'string') {
        return {
            type: 'doc',
            content: value
                .split(/\n\s*\n/)
                .map((p) => p.trim())
                .filter(Boolean)
                .map((p) => ({
                    type: 'paragraph',
                    content: [{ type: 'text', text: p }],
                })),
        };
    }

    return value &&
        typeof value === 'object' &&
        (value as RichNode).type === 'doc'
        ? (value as RichNode)
        : { type: 'doc', content: [] };
}

export default defineComponent({
    name: 'RichText',
    props: {
        doc: {
            type: [Object, String] as PropType<RichNode | string | null>,
            default: null,
        },
    },
    setup(props) {
        return () =>
            h(
                'div',
                { class: 'flex flex-col gap-4' },
                renderNodes(toDoc(props.doc).content),
            );
    },
});
