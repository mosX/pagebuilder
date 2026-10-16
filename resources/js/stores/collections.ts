import { defineStore } from 'pinia';
import { ref } from 'vue';
import { send } from '@/lib/http';
import collections from '@/routes/api/collections';
import type { CollectionSchema } from '@/types';

/**
 * Collections of the current company, used by the sidebar and relation pickers.
 */
export const useCollectionsStore = defineStore('collections', () => {
    const items = ref<CollectionSchema[]>([]);
    const loaded = ref(false);

    async function load() {
        const result = await send<{ data: CollectionSchema[] }>(
            collections.index(),
        );
        items.value = result.data;
        loaded.value = true;
    }

    function find(name: string) {
        return items.value.find((c) => c.name === name);
    }

    function reset() {
        items.value = [];
        loaded.value = false;
    }

    return { items, loaded, load, find, reset };
});
