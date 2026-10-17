<script setup lang="ts">
type Plan = {
    name: string;
    price: string;
    period: string;
    features: string;
    button_label: string;
    button_url: string;
    highlighted: boolean;
};

defineProps<{
    block: {
        title: string;
        items: Plan[];
    };
}>();

function lines(text: string) {
    return text
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean);
}
</script>

<template>
    <div class="flex flex-col gap-8">
        <h2
            v-if="block.title"
            class="text-center font-heading text-3xl font-bold text-ink"
        >
            {{ block.title }}
        </h2>
        <div class="flex flex-wrap justify-center gap-6">
            <div
                v-for="(plan, i) in block.items"
                :key="i"
                class="flex w-full max-w-sm flex-col gap-5 rounded-site border-2 bg-surface p-6"
                :class="
                    plan.highlighted
                        ? 'border-brand shadow-lg'
                        : 'border-gray-200'
                "
            >
                <h3 class="font-heading text-xl font-semibold text-ink">
                    {{ plan.name }}
                </h3>
                <div class="flex items-baseline gap-2">
                    <span class="font-heading text-4xl font-bold text-ink">
                        {{ plan.price }}
                    </span>
                    <span v-if="plan.period" class="text-gray-500">
                        {{ plan.period }}
                    </span>
                </div>
                <ul class="flex flex-1 flex-col gap-2 text-gray-700">
                    <li
                        v-for="(line, j) in lines(plan.features)"
                        :key="j"
                        class="flex gap-2"
                    >
                        <span class="text-brand" aria-hidden="true">✓</span>
                        {{ line }}
                    </li>
                </ul>
                <a
                    v-if="plan.button_label"
                    :href="plan.button_url || '#'"
                    class="rounded-site px-5 py-2.5 text-center font-medium transition"
                    :class="
                        plan.highlighted
                            ? 'bg-brand text-brand-contrast hover:bg-brand/90'
                            : 'border border-brand text-brand hover:bg-brand/5'
                    "
                >
                    {{ plan.button_label }}
                </a>
            </div>
        </div>
    </div>
</template>
