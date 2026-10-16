<script setup lang="ts">
import { Rank } from '@element-plus/icons-vue';
import { VueDraggable } from 'vue-draggable-plus';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { blocks } from '@/blocks/registry';
import type { BlockNode } from '@/blocks/registry';
import { useBlockEditor } from '@/components/blocks/editor/context';
import { send } from '@/lib/http';
import { resolve } from '@/routes/api/blocks';

const props = defineProps<{ node: BlockNode }>();

const editor = useBlockEditor();
const definition = computed(() => blocks[props.node.type]);
const isSelected = computed(() => editor.selectedId.value === props.node.id);

// Nodes are reactive objects of the editor's tree, edited in place.
const children = computed({
    get: () => props.node.children ?? [],
    set: (value: BlockNode[]) => {
        props.node.children = value;
    },
});

// Data blocks preview real company data; refetch shortly after their settings change.
const data = ref<unknown>(undefined);
let timer: ReturnType<typeof setTimeout> | undefined;

if (definition.value?.data) {
    watch(
        // Also refetch when the template's sample record changes.
        () =>
            JSON.stringify([
                props.node.props,
                editor.template.value?.recordId.value,
            ]),
        () => {
            clearTimeout(timer);
            timer = setTimeout(async () => {
                const template = editor.template.value;
                const result = await send<{ data: unknown }>(resolve(), {
                    type: props.node.type,
                    props: props.node.props,
                    collection: template?.collection.name,
                    record_id: template?.recordId.value ?? undefined,
                });
                data.value = result.data;
            }, 300);
        },
        { immediate: true },
    );
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div
        v-if="definition"
        class="group relative outline-offset-2"
        :class="
            isSelected
                ? 'outline-2 outline-[var(--el-color-primary)] outline-solid'
                : 'hover:outline-1 hover:outline-[var(--el-color-primary-light-5)] hover:outline-dashed'
        "
        @click.stop="editor.select(node.id)"
    >
        <div
            class="absolute -top-3 left-2 z-20 items-center gap-1 rounded bg-[var(--el-color-primary)] px-1.5 py-0.5 text-xs text-white shadow"
            :class="isSelected ? 'flex' : 'hidden group-hover:flex'"
        >
            <el-icon class="block-handle cursor-move"><Rank /></el-icon>
            {{ definition.label }}
        </div>

        <component :is="definition.component" :block="node.props" :data="data">
            <template v-if="definition.children" #default="{ containerClass }">
                <VueDraggable
                    v-model="children"
                    group="blocks"
                    handle=".block-handle"
                    :animation="150"
                    ghost-class="opacity-40"
                    :class="[
                        containerClass,
                        'min-h-16 empty:rounded-md empty:border-2 empty:border-dashed empty:border-gray-300',
                    ]"
                >
                    <EditorNode
                        v-for="child in children"
                        :key="child.id"
                        :node="child"
                    />
                </VueDraggable>
            </template>
        </component>
    </div>
</template>
