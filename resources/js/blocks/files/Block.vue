<script setup lang="ts">
import { computed } from 'vue';

type FileItem = { title: string; file: string; note: string };

const props = defineProps<{
    block: {
        title: string;
        items: FileItem[];
    };
}>();

const items = computed(() => props.block.items ?? []);

/** The file extension shown on the badge ("PDF"), from the link. */
function extension(url: string) {
    const match = url.split('?')[0].match(/\.([a-z0-9]{1,5})$/i);

    return match ? match[1].toUpperCase() : 'FILE';
}
</script>

<template>
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-4">
        <h2
            v-if="block.title"
            class="font-heading text-2xl font-semibold text-ink"
        >
            {{ block.title }}
        </h2>
        <ul
            class="flex flex-col divide-y divide-gray-200 rounded-site border border-gray-200"
        >
            <li v-for="(item, index) in items" :key="index">
                <component
                    :is="item.file ? 'a' : 'div'"
                    :href="item.file || undefined"
                    :download="item.file ? '' : undefined"
                    target="_blank"
                    rel="noopener"
                    class="flex items-center gap-4 p-4 transition"
                    :class="{ 'hover:bg-brand/5': item.file }"
                >
                    <span
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-site bg-brand/10 text-xs font-bold text-brand"
                    >
                        {{ extension(item.file) }}
                    </span>
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span class="truncate font-medium text-ink">{{
                            item.title
                        }}</span>
                        <span v-if="item.note" class="text-sm text-gray-500">{{
                            item.note
                        }}</span>
                        <span v-if="!item.file" class="text-sm text-gray-400">
                            Выберите файл в настройках блока
                        </span>
                    </span>
                    <span
                        v-if="item.file"
                        class="text-sm font-medium text-brand"
                        aria-hidden="true"
                        >Скачать ↓</span
                    >
                </component>
            </li>
        </ul>
    </div>
</template>
