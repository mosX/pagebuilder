<script setup lang="ts">
import { computed } from 'vue';
import { normalizeTree } from '@/blocks/registry';
import BlockNode from '@/components/blocks/BlockNode.vue';
import type { SitePayload } from '@/site/types';

/**
 * A company's public site. Rendered to HTML on the server (site-ssr.ts) and hydrated
 * in the browser (site.ts) from the same payload, so both outputs match.
 */
const props = defineProps<SitePayload>();

const blocks = computed(() => normalizeTree(props.page?.blocks));
</script>

<template>
    <div class="flex min-h-screen flex-col bg-white text-gray-900">
        <header class="border-b border-gray-200">
            <div
                class="mx-auto flex max-w-6xl flex-wrap items-center gap-6 px-4 py-4"
            >
                <a :href="site.base_url || '/'" class="text-lg font-semibold">
                    {{ site.name }}
                </a>
                <nav class="flex flex-wrap gap-4 text-sm text-gray-600">
                    <a
                        v-for="item in site.pages"
                        :key="item.slug"
                        :href="item.url"
                        class="hover:text-gray-900"
                        :class="{
                            'font-medium text-gray-900':
                                item.slug === page?.slug,
                        }"
                    >
                        {{ item.title }}
                    </a>
                </nav>
            </div>
        </header>

        <main class="flex-1">
            <BlockNode v-for="node in blocks" :key="node.id" :node="node" />

            <div v-if="!page" class="px-4 py-32 text-center">
                <div class="text-6xl font-bold text-gray-300">404</div>
                <p class="mt-4 text-gray-500">Страница не найдена</p>
            </div>
        </main>

        <footer
            class="border-t border-gray-200 py-6 text-center text-xs text-gray-400"
        >
            © {{ site.name }}
        </footer>
    </div>
</template>
