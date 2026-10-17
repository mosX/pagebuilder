<script setup lang="ts">
import {
    ChatDotRound,
    ChatLineRound,
    CopyDocument,
    Document,
    EditPen,
    Flag,
    Grid,
    List,
    Medal,
    Menu,
    Picture,
    PictureRounded,
    Pointer,
    Postcard,
    PriceTag,
    QuestionFilled,
    Search,
    Sort,
    Star,
    Tickets,
    User,
    VideoPlay,
} from '@element-plus/icons-vue';
import { VueDraggable } from 'vue-draggable-plus';
import type { Component } from 'vue';
import { computed, onMounted, ref } from 'vue';
import { blocks, categories, createNode } from '@/blocks/registry';
import type { BlockDefinition } from '@/blocks/registry';
import { useBlockEditor } from '@/components/blocks/editor/context';
import { usePatternsStore } from '@/stores/patterns';
import type { PatternItem } from '@/stores/patterns';

/** Icons referenced by name in block.json. */
const icons: Record<string, Component> = {
    ChatDotRound,
    ChatLineRound,
    CopyDocument,
    Document,
    EditPen,
    Flag,
    Grid,
    List,
    Medal,
    Menu,
    Picture,
    PictureRounded,
    Pointer,
    Postcard,
    PriceTag,
    QuestionFilled,
    Search,
    Sort,
    Star,
    Tickets,
    User,
    VideoPlay,
};

const editor = useBlockEditor();
const patterns = usePatternsStore();

onMounted(() => {
    if (!patterns.loaded) {
        void patterns.load();
    }
});

/** A reference to a saved section. */
function patternNode(id: number) {
    const node = createNode('pattern');
    node.props.pattern = id;

    return node;
}

// Each category is its own clone-only drag source.
const allGroups = ref(
    categories.map((category) => ({
        ...category,
        items: Object.values(blocks).filter(
            (b) => b.category === category.key && !b.hidden,
        ),
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
        <div v-if="patterns.items.length">
            <div
                class="mb-2 text-xs font-medium tracking-wide text-[var(--el-text-color-secondary)] uppercase"
            >
                Мои секции
            </div>
            <VueDraggable
                v-model="patterns.items"
                :group="{ name: 'blocks', pull: 'clone', put: false }"
                :sort="false"
                :clone="(item: PatternItem) => patternNode(item.id)"
                class="flex flex-col gap-1"
            >
                <button
                    v-for="item in patterns.items"
                    :key="item.id"
                    type="button"
                    class="flex cursor-grab items-center gap-2 rounded-md border border-[var(--el-border-color)] bg-white px-2 py-1.5 text-left text-xs transition hover:border-[var(--el-color-primary)] hover:text-[var(--el-color-primary)]"
                    @click="editor.insertNode(patternNode(item.id))"
                >
                    <el-icon><CopyDocument /></el-icon>
                    <span class="truncate">{{ item.name }}</span>
                </button>
            </VueDraggable>
        </div>
        <p class="text-xs text-[var(--el-text-color-secondary)]">
            Перетащите блок на страницу или нажмите на него, чтобы добавить
            после выбранного.
        </p>
    </div>
</template>
