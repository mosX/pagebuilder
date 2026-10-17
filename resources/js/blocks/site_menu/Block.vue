<script setup lang="ts">
import { computed, inject } from 'vue';
import { currentPageKey } from '@/site/current-page';

type MenuItem = {
    label: string;
    url: string;
    slug: string | null;
    children: MenuItem[];
};

const props = defineProps<{
    block: {
        variant: 'default' | 'muted' | 'button';
        collapse: boolean;
    };
    /** The company menu with final URLs (resolved by the server). */
    data?: { items: MenuItem[] };
}>();

const current = inject(currentPageKey, null);
const items = computed(() => props.data?.items ?? []);

function isActive(item: MenuItem) {
    return !!item.slug && item.slug === current?.value;
}

const link = {
    default: 'text-ink/80 hover:text-brand',
    muted: 'text-sm text-gray-500 hover:text-ink',
    button: 'rounded-site px-3 py-1.5 hover:bg-brand/10 text-ink',
};
</script>

<template>
    <nav aria-label="Меню сайта">
        <!-- Desktop: inline links; nested items open on hover/focus. -->
        <ul
            class="flex-wrap items-center gap-x-6 gap-y-2"
            :class="block.collapse ? 'hidden @3xl:flex' : 'flex'"
        >
            <li v-for="item in items" :key="item.label" class="group relative">
                <a
                    :href="item.url"
                    class="transition"
                    :class="[
                        link[block.variant],
                        { 'font-semibold text-brand': isActive(item) },
                    ]"
                >
                    {{ item.label }}
                    <span v-if="item.children.length" aria-hidden="true"
                        >▾</span
                    >
                </a>
                <ul
                    v-if="item.children.length"
                    class="invisible absolute left-0 z-30 mt-2 min-w-48 rounded-site border border-gray-200 bg-surface py-2 opacity-0 shadow-lg transition group-focus-within:visible group-focus-within:opacity-100 group-hover:visible group-hover:opacity-100"
                >
                    <li v-for="child in item.children" :key="child.label">
                        <a
                            :href="child.url"
                            class="block px-4 py-1.5 text-sm text-ink hover:bg-brand/10"
                        >
                            {{ child.label }}
                        </a>
                    </li>
                </ul>
            </li>
        </ul>

        <!-- Phone: a disclosure ("burger") that works without JavaScript and in SSR. -->
        <details v-if="block.collapse" class="relative @3xl:hidden">
            <summary
                class="flex h-10 w-10 cursor-pointer list-none items-center justify-center rounded-site text-2xl text-ink hover:bg-brand/10"
                aria-label="Открыть меню"
            >
                ☰
            </summary>
            <ul
                class="absolute right-0 z-30 mt-2 flex min-w-56 flex-col rounded-site border border-gray-200 bg-surface py-2 shadow-lg"
            >
                <template v-for="item in items" :key="item.label">
                    <li>
                        <a
                            :href="item.url"
                            class="block px-4 py-2 text-ink hover:bg-brand/10"
                            :class="{
                                'font-semibold text-brand': isActive(item),
                            }"
                        >
                            {{ item.label }}
                        </a>
                    </li>
                    <li
                        v-for="child in item.children"
                        :key="`${item.label}-${child.label}`"
                    >
                        <a
                            :href="child.url"
                            class="block py-1.5 pr-4 pl-8 text-sm text-gray-600 hover:bg-brand/10"
                        >
                            {{ child.label }}
                        </a>
                    </li>
                </template>
            </ul>
        </details>

        <span v-if="!items.length" class="text-sm text-gray-400">
            Меню пустое — добавьте пункты в разделе «Сайт»
        </span>
    </nav>
</template>
