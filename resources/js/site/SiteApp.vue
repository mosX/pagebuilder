<script setup lang="ts">
import { computed, provide } from 'vue';
import { normalizeTree } from '@/blocks/registry';
import BlockNode from '@/components/blocks/BlockNode.vue';
import { currentPageKey, currentSiteKey } from '@/site/current-page';
import type { SitePayload } from '@/site/types';

/**
 * A company's public site. Rendered to HTML on the server (site-ssr.ts) and hydrated
 * in the browser (site.ts) from the same payload, so both outputs match.
 */
const props = defineProps<SitePayload>();

const header = computed(() => normalizeTree(props.layout?.header));
const blocks = computed(() => normalizeTree(props.page?.blocks));
const footer = computed(() => normalizeTree(props.layout?.footer));

// The menu highlights the current page.
provide(
    currentPageKey,
    computed(() => props.page?.slug ?? null),
);

// Search, account and language blocks link within the current site.
provide(
    currentSiteKey,
    computed(() => props.site),
);
</script>

<template>
    <div
        class="@container flex min-h-screen flex-col bg-surface font-body text-ink"
        :style="theme"
    >
        <header>
            <BlockNode v-for="node in header" :key="node.id" :node="node" />
        </header>

        <main class="flex-1">
            <BlockNode v-for="node in blocks" :key="node.id" :node="node" />

            <div v-if="!page" class="px-4 py-32 text-center">
                <div class="font-heading text-6xl font-bold text-gray-300">
                    404
                </div>
                <p class="mt-4 text-gray-500">Страница не найдена</p>
            </div>
        </main>

        <footer>
            <BlockNode v-for="node in footer" :key="node.id" :node="node" />
        </footer>
    </div>
</template>
