<script setup lang="ts">
import { computed, inject } from 'vue';
import { currentSiteKey } from '@/site/current-page';

defineProps<{
    block: {
        placeholder: string;
        variant: 'field' | 'compact';
    };
}>();

const site = inject(currentSiteKey, null);
// In the editor there is no site: the form is shown but goes nowhere.
const action = computed(() => site?.value?.search_url ?? undefined);
</script>

<template>
    <form
        :action="action"
        method="get"
        role="search"
        class="flex items-center gap-2"
        :class="block.variant === 'compact' ? 'max-w-56' : 'w-full max-w-md'"
        @submit="!action && $event.preventDefault()"
    >
        <input
            type="search"
            name="q"
            :placeholder="block.placeholder"
            :aria-label="block.placeholder || 'Поиск'"
            class="min-w-0 flex-1 rounded-site border border-gray-300 bg-surface px-3 text-ink focus:border-brand focus:outline-none"
            :class="block.variant === 'compact' ? 'py-1.5 text-sm' : 'py-2'"
        />
        <button
            v-if="block.variant === 'field'"
            type="submit"
            class="rounded-site bg-brand px-4 py-2 font-medium text-brand-contrast transition hover:bg-brand/90"
        >
            Найти
        </button>
    </form>
</template>
