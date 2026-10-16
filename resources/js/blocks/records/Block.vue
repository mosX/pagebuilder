<script setup lang="ts">
type RecordsData = {
    collection: string | null;
    items: {
        id: number;
        title: string;
        subtitle: string | null;
        image: string | null;
        url: string | null;
    }[];
    page: number;
    last_page: number;
};

defineProps<{
    block: {
        collection: string;
        title: string;
        columns: '1' | '2' | '3' | '4';
    };
    /** Resolved by the server from the selected collection. */
    data?: RecordsData;
}>();

const columns = {
    '1': 'grid-cols-1',
    '2': 'grid-cols-1 sm:grid-cols-2',
    '3': 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
    '4': 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-4',
};

/** Pagination keeps the visitor on the same page: ?page=N. */
function pageUrl(page: number) {
    return page > 1 ? `?page=${page}` : '?';
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <h2 v-if="block.title" class="text-2xl font-semibold text-gray-900">
            {{ block.title }}
        </h2>

        <div
            v-if="!block.collection"
            class="rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-400"
        >
            Выберите коллекцию в настройках блока
        </div>
        <div
            v-else-if="data && !data.items.length"
            class="rounded-lg bg-gray-50 p-6 text-center text-sm text-gray-400"
        >
            Записей пока нет
        </div>
        <div v-else class="grid gap-4" :class="columns[block.columns]">
            <component
                :is="item.url ? 'a' : 'article'"
                v-for="item in data?.items ?? []"
                :key="item.id"
                :href="item.url ?? undefined"
                class="flex flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
                :class="{ 'transition hover:shadow-md': item.url }"
            >
                <img
                    v-if="item.image"
                    :src="item.image"
                    alt=""
                    loading="lazy"
                    class="aspect-video w-full object-cover"
                />
                <div class="p-5">
                    <h3 class="font-semibold text-gray-900">
                        {{ item.title }}
                    </h3>
                    <p v-if="item.subtitle" class="mt-1 text-sm text-gray-500">
                        {{ item.subtitle }}
                    </p>
                </div>
            </component>
        </div>

        <nav
            v-if="data && data.last_page > 1"
            class="flex items-center justify-center gap-4 text-sm"
        >
            <a
                v-if="data.page > 1"
                :href="pageUrl(data.page - 1)"
                class="text-blue-600 hover:underline"
            >
                ← Назад
            </a>
            <span class="text-gray-500"
                >{{ data.page }} / {{ data.last_page }}</span
            >
            <a
                v-if="data.page < data.last_page"
                :href="pageUrl(data.page + 1)"
                class="text-blue-600 hover:underline"
            >
                Вперёд →
            </a>
        </nav>
    </div>
</template>
