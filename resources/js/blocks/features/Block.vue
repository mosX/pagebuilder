<script setup lang="ts">
defineProps<{
    block: {
        title: string;
        columns: '2' | '3' | '4';
        items: { icon: string; title: string; text: string }[];
    };
}>();

const columns = {
    '2': '@2xl:grid-cols-2',
    '3': '@2xl:grid-cols-2 @5xl:grid-cols-3',
    '4': '@2xl:grid-cols-2 @5xl:grid-cols-4',
};
</script>

<template>
    <div class="flex flex-col gap-8">
        <h2
            v-if="block.title"
            class="text-center font-heading text-3xl font-bold text-ink"
        >
            {{ block.title }}
        </h2>
        <div class="grid grid-cols-1 gap-6" :class="columns[block.columns]">
            <div
                v-for="(item, i) in block.items"
                :key="i"
                class="flex flex-col gap-3 rounded-site border border-gray-200 bg-surface p-6"
            >
                <span
                    v-if="item.icon"
                    class="flex h-12 w-12 items-center justify-center rounded-site bg-brand/10 text-2xl"
                    aria-hidden="true"
                >
                    {{ item.icon }}
                </span>
                <h3 class="font-heading text-lg font-semibold text-ink">
                    {{ item.title }}
                </h3>
                <p class="whitespace-pre-line text-gray-600">{{ item.text }}</p>
            </div>
        </div>
    </div>
</template>
