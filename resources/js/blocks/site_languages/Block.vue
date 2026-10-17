<script setup lang="ts">
import { computed, inject } from 'vue';
import { currentSiteKey } from '@/site/current-page';
import type { SiteLanguage } from '@/site/types';

defineProps<{
    block: {
        variant: 'codes' | 'names' | 'dropdown';
    };
}>();

const site = inject(currentSiteKey, null);
const inEditor = computed(() => !site?.value);

// A sample in the editor, so the block can be styled before languages are set up.
const languages = computed<SiteLanguage[]>(() =>
    inEditor.value
        ? [
              { code: 'ru', label: 'Русский', url: '#', active: true },
              { code: 'en', label: 'English', url: '#', active: false },
          ]
        : (site?.value?.languages ?? []),
);
const active = computed(() => languages.value.find((l) => l.active));
</script>

<template>
    <nav v-if="languages.length" aria-label="Язык сайта" class="text-sm">
        <!-- A disclosure, so it works without JavaScript and in SSR. -->
        <details v-if="block.variant === 'dropdown'" class="relative">
            <summary
                class="flex cursor-pointer list-none items-center gap-1 rounded-site px-2 py-1 text-ink hover:bg-brand/10"
            >
                {{ active?.label ?? active?.code }}
                <span aria-hidden="true">▾</span>
            </summary>
            <ul
                class="absolute right-0 z-30 mt-1 min-w-36 rounded-site border border-gray-200 bg-surface py-1 shadow-lg"
            >
                <li v-for="language in languages" :key="language.code">
                    <a
                        :href="language.url"
                        :hreflang="language.code"
                        class="block px-3 py-1.5 text-ink hover:bg-brand/10"
                        :class="{ 'font-semibold text-brand': language.active }"
                    >
                        {{ language.label }}
                    </a>
                </li>
            </ul>
        </details>

        <ul v-else class="flex items-center gap-1">
            <li
                v-for="(language, index) in languages"
                :key="language.code"
                class="flex items-center gap-1"
            >
                <span v-if="index" class="text-gray-300" aria-hidden="true"
                    >·</span
                >
                <a
                    :href="language.url"
                    :hreflang="language.code"
                    :aria-current="language.active ? 'true' : undefined"
                    class="px-1 transition hover:text-brand"
                    :class="
                        language.active
                            ? 'font-semibold text-brand'
                            : 'text-ink/70'
                    "
                >
                    {{
                        block.variant === 'names'
                            ? language.label
                            : language.code.toUpperCase()
                    }}
                </a>
            </li>
        </ul>
    </nav>
</template>
