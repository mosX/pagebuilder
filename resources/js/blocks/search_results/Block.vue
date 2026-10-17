<script setup lang="ts">
import { computed } from 'vue';

type SearchResult = { title: string; url: string; excerpt: string };

const props = defineProps<{
    block: Record<string, never>;
    /** Filled by the server on the /search page. */
    data?: {
        action: string;
        query: string;
        min_length: number;
        results: SearchResult[];
    };
}>();

const query = computed(() => props.data?.query ?? '');
const tooShort = computed(
    () =>
        query.value !== '' &&
        query.value.length < (props.data?.min_length ?? 2),
);
</script>

<template>
    <section class="@container px-4 py-12 @3xl:py-16">
        <div class="mx-auto flex max-w-3xl flex-col gap-8">
            <h1 class="font-heading text-3xl font-bold text-ink">Поиск</h1>

            <form
                :action="data?.action"
                method="get"
                role="search"
                class="flex gap-2"
            >
                <input
                    type="search"
                    name="q"
                    :value="query"
                    placeholder="Что вы ищете?"
                    aria-label="Поисковый запрос"
                    class="min-w-0 flex-1 rounded-site border border-gray-300 bg-surface px-4 py-2.5 text-ink focus:border-brand focus:outline-none"
                />
                <button
                    type="submit"
                    class="rounded-site bg-brand px-5 py-2.5 font-medium text-brand-contrast transition hover:bg-brand/90"
                >
                    Найти
                </button>
            </form>

            <p v-if="tooShort" class="text-gray-500">
                Введите хотя бы {{ data?.min_length }} символа.
            </p>
            <template v-else-if="query">
                <p class="text-sm text-gray-500">
                    {{
                        data?.results.length
                            ? `Найдено: ${data.results.length}`
                            : `По запросу «${query}» ничего не найдено.`
                    }}
                </p>

                <ol class="flex flex-col gap-6">
                    <li v-for="result in data?.results ?? []" :key="result.url">
                        <a
                            :href="result.url"
                            class="font-heading text-xl font-semibold text-brand hover:underline"
                        >
                            {{ result.title }}
                        </a>
                        <p
                            v-if="result.excerpt"
                            class="mt-1 text-sm leading-relaxed text-ink/70"
                        >
                            {{ result.excerpt }}
                        </p>
                    </li>
                </ol>
            </template>
        </div>
    </section>
</template>
