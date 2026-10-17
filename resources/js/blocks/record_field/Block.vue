<script setup lang="ts">
import { computed } from 'vue';

type FieldData = {
    type: string | null;
    value: string | null;
    items: { label: string; url: string | null }[];
};

const props = defineProps<{
    block: {
        field: string;
        style: 'h1' | 'h2' | 'text' | 'meta' | 'image' | 'tags';
        label: string;
        align: 'left' | 'center' | 'right';
    };
    /** The value of the field for the record being rendered (resolved by the server). */
    data?: FieldData;
}>();

const align = {
    left: 'text-left justify-start',
    center: 'text-center justify-center',
    right: 'text-right justify-end',
};

const paragraphs = computed(() =>
    (props.data?.value ?? '')
        .split(/\n\s*\n/)
        .map((p) => p.trim())
        .filter(Boolean),
);
</script>

<template>
    <div :class="align[block.align]">
        <div
            v-if="!block.field"
            class="rounded border border-dashed border-gray-300 p-3 text-sm text-gray-400"
        >
            Выберите поле записи в настройках блока
        </div>

        <template v-else-if="data?.value || data?.items.length">
            <span v-if="block.label" class="mr-1 text-sm text-gray-500">
                {{ block.label }}
            </span>

            <h1
                v-if="block.style === 'h1'"
                class="font-heading text-4xl font-bold text-ink"
            >
                {{ data.value }}
            </h1>
            <h2
                v-else-if="block.style === 'h2'"
                class="font-heading text-2xl font-semibold text-ink"
            >
                {{ data.value }}
            </h2>
            <img
                v-else-if="block.style === 'image' && data.type === 'image'"
                :src="data.value ?? ''"
                alt=""
                class="h-auto w-full rounded-site object-cover"
            />
            <div
                v-else-if="block.style === 'tags' && data.items.length"
                class="inline-flex flex-wrap gap-2"
            >
                <component
                    :is="item.url ? 'a' : 'span'"
                    v-for="item in data.items"
                    :key="item.label"
                    :href="item.url ?? undefined"
                    class="rounded-full bg-gray-100 px-3 py-1 text-sm text-gray-700"
                    :class="{ 'hover:bg-gray-200': item.url }"
                >
                    {{ item.label }}
                </component>
            </div>
            <span
                v-else-if="block.style === 'meta'"
                class="text-sm text-gray-500"
            >
                {{ data.value }}
            </span>
            <div
                v-else
                class="flex flex-col gap-4 leading-relaxed text-gray-700"
            >
                <p
                    v-for="(paragraph, i) in paragraphs"
                    :key="i"
                    class="whitespace-pre-line"
                >
                    {{ paragraph }}
                </p>
            </div>
        </template>
    </div>
</template>
