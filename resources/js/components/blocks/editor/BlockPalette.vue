<script setup lang="ts">
import {
    Document,
    EditPen,
    Grid,
    List,
    Picture,
    Pointer,
    Postcard,
    Sort,
    Star,
    Tickets,
} from '@element-plus/icons-vue';
import { VueDraggable } from 'vue-draggable-plus';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import { blocks, categories, createNode } from '@/blocks/registry';
import type { BlockDefinition } from '@/blocks/registry';
import { useBlockEditor } from '@/components/blocks/editor/context';

/** Icons referenced by name in block.json. */
const icons: Record<string, Component> = {
    Document,
    EditPen,
    Grid,
    List,
    Picture,
    Pointer,
    Postcard,
    Sort,
    Star,
    Tickets,
};

const editor = useBlockEditor();

// Each category is its own clone-only drag source.
const allGroups = ref(
    categories.map((category) => ({
        ...category,
        items: Object.values(blocks).filter((b) => b.category === category.key),
    })),
);

// Record blocks only make sense inside a record page template (known after loading).
const groups = computed(() =>
    allGroups.value.filter(
        (group) =>
            group.items.length &&
            (group.key !== 'record' || editor.template.value),
    ),
);
</script>

<template>
    <div class="flex flex-col gap-4">
        <div v-for="group in groups" :key="group.key">
            <div
                class="mb-2 text-xs font-medium tracking-wide text-[var(--el-text-color-secondary)] uppercase"
            >
                {{ group.label }}
            </div>
            <VueDraggable
                v-model="group.items"
                :group="{ name: 'blocks', pull: 'clone', put: false }"
                :sort="false"
                :clone="(item: BlockDefinition) => createNode(item.name)"
                class="grid grid-cols-2 gap-2"
            >
                <button
                    v-for="item in group.items"
                    :key="item.name"
                    type="button"
                    :title="item.description ?? item.label"
                    class="flex cursor-grab flex-col items-center gap-1 rounded-md border border-[var(--el-border-color)] bg-white p-2 text-xs text-[var(--el-text-color-regular)] transition hover:border-[var(--el-color-primary)] hover:text-[var(--el-color-primary)]"
                    @click="editor.insert(item.name)"
                >
                    <el-icon :size="20">
                        <component :is="icons[item.icon] ?? Document" />
                    </el-icon>
                    {{ item.label }}
                </button>
            </VueDraggable>
        </div>
        <p class="text-xs text-[var(--el-text-color-secondary)]">
            Перетащите блок на страницу или нажмите на него, чтобы добавить
            после выбранного.
        </p>
    </div>
</template>
