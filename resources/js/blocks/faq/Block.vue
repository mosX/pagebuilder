<script setup lang="ts">
defineProps<{
    block: {
        title: string;
        items: { question: string; answer: string }[];
    };
}>();
</script>

<template>
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
        <h2
            v-if="block.title"
            class="text-center font-heading text-3xl font-bold text-ink"
        >
            {{ block.title }}
        </h2>
        <div
            class="flex flex-col divide-y divide-gray-200 border-y border-gray-200"
        >
            <!-- Native disclosure: works without JavaScript and in SSR. -->
            <details
                v-for="(item, i) in block.items"
                :key="i"
                class="group py-4"
            >
                <summary
                    class="flex cursor-pointer list-none items-center justify-between gap-4 font-medium text-ink"
                >
                    {{ item.question }}
                    <span
                        class="text-xl text-brand transition group-open:rotate-45"
                        aria-hidden="true"
                    >
                        +
                    </span>
                </summary>
                <p class="mt-3 whitespace-pre-line text-gray-600">
                    {{ item.answer }}
                </p>
            </details>
        </div>
    </div>
</template>
