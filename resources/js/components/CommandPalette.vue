<script setup lang="ts">
import { Search } from '@element-plus/icons-vue';
import type { Component } from 'vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import type { RouteLocationRaw } from 'vue-router';
import { useRouter } from 'vue-router';
import { send } from '@/lib/http';
import { adminSearch } from '@/routes/api';
import { useSessionStore } from '@/stores/session';

type Section = { path: string; label: string; icon: Component };

type SearchResponse = {
    records: {
        collection: string;
        collection_label: string;
        id: number;
        title: string;
    }[];
    collections: { name: string; label: string }[];
    patterns: { id: number; name: string }[];
    media: { id: number; name: string; url: string; kind: string }[];
};

type Item = {
    key: string;
    group: string;
    label: string;
    hint?: string;
    to: RouteLocationRaw;
};

/**
 * Quick search over the admin (Ctrl+K / ⌘K): sections of the menu, records of every
 * collection, collections, saved sections and media files.
 */
const props = defineProps<{ sections: Section[] }>();

const router = useRouter();
const session = useSessionStore();
const open = ref(false);
const query = ref('');
const results = ref<SearchResponse | null>(null);
const loading = ref(false);
const active = ref(0);
const input = ref<{ focus: () => void } | null>(null);

const items = computed<Item[]>(() => {
    const q = query.value.trim().toLowerCase();
    const list: Item[] = props.sections
        .filter((s) => !q || s.label.toLowerCase().includes(q))
        .slice(0, q ? 5 : 8)
        .map((s) => ({
            key: `s:${s.path}`,
            group: 'Разделы',
            label: s.label,
            to: s.path,
        }));

    const found = results.value;

    if (!found || q.length < 2) {
        return list;
    }

    for (const r of found.records) {
        list.push({
            key: `r:${r.collection}:${r.id}`,
            group: 'Записи',
            label: r.title,
            hint: r.collection_label,
            to: {
                name: 'records',
                params: { name: r.collection },
                query: { record: r.id },
            },
        });
    }

    for (const c of found.collections) {
        list.push({
            key: `c:${c.name}`,
            group: 'Коллекции',
            label: c.label,
            to: { name: 'records', params: { name: c.name } },
        });
    }

    for (const p of found.patterns) {
        list.push({
            key: `p:${p.id}`,
            group: 'Секции',
            label: p.name,
            to: { name: 'patterns.edit', params: { id: p.id } },
        });
    }

    for (const m of found.media) {
        list.push({
            key: `m:${m.id}`,
            group: 'Медиатека',
            label: m.name,
            to: { name: 'media' },
        });
    }

    return list;
});

let timer: ReturnType<typeof setTimeout> | undefined;

watch(query, (value) => {
    active.value = 0;
    clearTimeout(timer);

    if (value.trim().length < 2 || !session.can('content.view')) {
        results.value = null;

        return;
    }

    timer = setTimeout(async () => {
        loading.value = true;

        try {
            results.value = await send<SearchResponse>(adminSearch(), {
                q: value.trim(),
            });
        } finally {
            loading.value = false;
        }
    }, 250);
});

function show() {
    open.value = true;
    query.value = '';
    results.value = null;
    void nextTick(() => input.value?.focus());
}

function go(item: Item | undefined) {
    if (item) {
        open.value = false;
        void router.push(item.to);
    }
}

function onInputKey(event: KeyboardEvent) {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = Math.min(active.value + 1, items.value.length - 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = Math.max(active.value - 1, 0);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        go(items.value[active.value]);
    }
}

function onGlobalKey(event: KeyboardEvent) {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        show();
    }
}

onMounted(() => window.addEventListener('keydown', onGlobalKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onGlobalKey));

defineExpose({ show });
</script>

<template>
    <el-dialog
        v-model="open"
        width="min(640px, 95vw)"
        :show-close="false"
        align-center
        append-to-body
        class="command-palette"
    >
        <template #header>
            <el-input
                ref="input"
                v-model="query"
                size="large"
                placeholder="Поиск по записям, страницам и разделам…"
                :prefix-icon="Search"
                @keydown="onInputKey"
            />
        </template>

        <div v-loading="loading" class="max-h-[60vh] min-h-24 overflow-y-auto">
            <template v-for="(item, index) in items" :key="item.key">
                <div
                    v-if="index === 0 || items[index - 1].group !== item.group"
                    class="px-3 pt-3 pb-1 text-xs font-medium text-[var(--el-text-color-secondary)] uppercase"
                >
                    {{ item.group }}
                </div>
                <button
                    type="button"
                    class="flex w-full items-center justify-between gap-3 rounded px-3 py-2 text-left text-sm"
                    :class="
                        index === active
                            ? 'bg-[var(--el-color-primary-light-9)] text-[var(--el-color-primary)]'
                            : 'hover:bg-[var(--el-fill-color-light)]'
                    "
                    @mouseenter="active = index"
                    @click="go(item)"
                >
                    <span class="truncate">{{ item.label }}</span>
                    <span
                        v-if="item.hint"
                        class="shrink-0 text-xs text-[var(--el-text-color-secondary)]"
                    >
                        {{ item.hint }}
                    </span>
                </button>
            </template>
            <el-empty
                v-if="!loading && !items.length"
                :image-size="60"
                description="Ничего не найдено"
            />
        </div>

        <div
            class="mt-2 flex gap-4 border-t pt-2 text-xs text-[var(--el-text-color-secondary)]"
        >
            <span>↑↓ — выбрать</span>
            <span>Enter — открыть</span>
            <span>Esc — закрыть</span>
        </div>
    </el-dialog>
</template>
