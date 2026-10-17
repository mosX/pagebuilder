import { computed, inject, onBeforeUnmount, provide, ref, watch } from 'vue';
import type { InjectionKey, Ref } from 'vue';
import {
    blocks,
    cloneNode,
    createNode,
    locate,
    normalizeTree,
    serializeTree,
} from '@/blocks/registry';
import type { BlockNode, Device } from '@/blocks/registry';
import type { CollectionSchema } from '@/types';

/**
 * Set when editing the template of a collection's record pages: record blocks are
 * offered and data previews use the chosen sample record.
 */
export type TemplateTarget = {
    collection: CollectionSchema;
    recordId: Ref<number | null>;
};

/** Canvas widths of the device preview; blocks respond to it via container queries. */
export const deviceWidths: Record<Device, string> = {
    desktop: '100%',
    tablet: '820px',
    mobile: '390px',
};

const CLIPBOARD_KEY = 'pagebuilder:block-clipboard';
const HISTORY_LIMIT = 100;

/**
 * Editor state shared by the canvas, palette and inspector.
 */
export function createBlockEditor(
    tree: Ref<BlockNode[]>,
    template: Ref<TemplateTarget | null> = ref(null),
) {
    const selectedId = ref<string | null>(null);
    const device = ref<Device>('desktop');

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
    function insertNode(node: BlockNode) {
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

    function insert(type: string) {
        insertNode(createNode(type));
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

    // --- Clipboard: kept in localStorage, so blocks can be pasted into other pages.

    const clipboard = ref<string | null>(readClipboard());

    function copy(id: string) {
        const found = locate(tree.value, id);

        if (found) {
            clipboard.value = JSON.stringify(
                serializeTree([found.list[found.index]]),
            );
            writeClipboard(clipboard.value);
        }
    }

    function paste() {
        const [node] = normalizeTree(
            clipboard.value ? JSON.parse(clipboard.value) : [],
        );

        if (node) {
            insertNode(cloneNode(node));
        }
    }

    const onStorage = (event: StorageEvent) => {
        if (event.key === CLIPBOARD_KEY) {
            clipboard.value = event.newValue;
        }
    };
    window.addEventListener('storage', onStorage);
    onBeforeUnmount(() => window.removeEventListener('storage', onStorage));

    // --- History: snapshots of the serialized tree, recorded shortly after changes.

    const snapshots = ref<string[]>([]);
    const position = ref(-1);
    let restoring = false;
    let timer: ReturnType<typeof setTimeout> | undefined;

    function snapshot() {
        return JSON.stringify(serializeTree(tree.value));
    }

    function record() {
        const current = snapshot();

        if (current === snapshots.value[position.value]) {
            return;
        }

        snapshots.value = [
            ...snapshots.value.slice(0, position.value + 1),
            current,
        ].slice(-HISTORY_LIMIT);
        position.value = snapshots.value.length - 1;
    }

    watch(
        tree,
        () => {
            if (restoring) {
                return;
            }

            clearTimeout(timer);
            timer = setTimeout(record, 300);
        },
        { deep: true },
    );

    onBeforeUnmount(() => clearTimeout(timer));

    /** Starts a fresh history from the current tree (after loading). */
    function resetHistory() {
        clearTimeout(timer);
        snapshots.value = [snapshot()];
        position.value = 0;
    }

    function restore(index: number) {
        clearTimeout(timer);
        restoring = true;
        position.value = index;
        tree.value = normalizeTree(JSON.parse(snapshots.value[index]));

        // Keep the selection if the block still exists.
        if (selectedId.value && !locate(tree.value, selectedId.value)) {
            select(null);
        }

        setTimeout(() => (restoring = false));
    }

    const canUndo = computed(() => position.value > 0);
    const canRedo = computed(() => position.value < snapshots.value.length - 1);

    function undo() {
        // Record pending changes first, so they can be undone too.
        record();

        if (canUndo.value) {
            restore(position.value - 1);
        }
    }

    function redo() {
        if (canRedo.value) {
            restore(position.value + 1);
        }
    }

    const editor = {
        tree,
        template,
        device,
        selectedId,
        selected,
        select,
        insert,
        insertNode,
        remove,
        duplicate,
        move,
        clipboard,
        copy,
        paste,
        canUndo,
        canRedo,
        undo,
        redo,
        resetHistory,
    };
    provide(editorKey, editor);

    return editor;
}

function readClipboard(): string | null {
    try {
        return localStorage.getItem(CLIPBOARD_KEY);
    } catch {
        return null;
    }
}

function writeClipboard(value: string) {
    try {
        localStorage.setItem(CLIPBOARD_KEY, value);
    } catch {
        // Storage may be unavailable (private mode); the copy still works within this page.
    }
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
