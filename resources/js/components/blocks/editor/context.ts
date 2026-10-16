import { computed, inject, provide, ref } from 'vue';
import type { InjectionKey, Ref } from 'vue';
import { blocks, cloneNode, createNode, locate } from '@/blocks/registry';
import type { BlockNode } from '@/blocks/registry';
import type { CollectionSchema } from '@/types';

/**
 * Set when editing the template of a collection's record pages: record blocks are
 * offered and data previews use the chosen sample record.
 */
export type TemplateTarget = {
    collection: CollectionSchema;
    recordId: Ref<number | null>;
};

/**
 * Editor state shared by the canvas, palette and inspector.
 */
export function createBlockEditor(
    tree: Ref<BlockNode[]>,
    template: Ref<TemplateTarget | null> = ref(null),
) {
    const selectedId = ref<string | null>(null);

    const selected = computed(() => {
        const found = selectedId.value
            ? locate(tree.value, selectedId.value)
            : null;

        return found ? found.list[found.index] : null;
    });

    function select(id: string | null) {
        selectedId.value = id;
    }

    /**
     * Adds a block next to the selection: inside it when it is a container,
     * after it otherwise, or at the end of the page when nothing is selected.
     */
    function insert(type: string) {
        const node = createNode(type);
        const current = selected.value;

        if (current && blocks[current.type]?.children) {
            current.children = [...(current.children ?? []), node];
        } else if (current) {
            const found = locate(tree.value, current.id);
            found?.list.splice(found.index + 1, 0, node);
        } else {
            tree.value.push(node);
        }

        select(node.id);
    }

    function remove(id: string) {
        const found = locate(tree.value, id);
        found?.list.splice(found.index, 1);

        if (selectedId.value === id) {
            select(null);
        }
    }

    function duplicate(id: string) {
        const found = locate(tree.value, id);

        if (found) {
            const copy = cloneNode(found.list[found.index]);
            found.list.splice(found.index + 1, 0, copy);
            select(copy.id);
        }
    }

    function move(id: string, delta: number) {
        const found = locate(tree.value, id);
        const target = found ? found.index + delta : -1;

        if (found && target >= 0 && target < found.list.length) {
            const [node] = found.list.splice(found.index, 1);
            found.list.splice(target, 0, node);
        }
    }

    const editor = {
        tree,
        template,
        selectedId,
        selected,
        select,
        insert,
        remove,
        duplicate,
        move,
    };
    provide(editorKey, editor);

    return editor;
}

export type BlockEditor = ReturnType<typeof createBlockEditor>;

const editorKey: InjectionKey<BlockEditor> = Symbol('blockEditor');

export function useBlockEditor(): BlockEditor {
    const editor = inject(editorKey);

    if (!editor) {
        throw new Error(
            'useBlockEditor() must be used inside the block editor.',
        );
    }

    return editor;
}
