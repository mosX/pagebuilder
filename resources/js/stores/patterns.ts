import { defineStore } from 'pinia';
import { ref } from 'vue';
import { send } from '@/lib/http';
import patterns from '@/routes/api/patterns';

export type PatternItem = { id: number; name: string; updated_at: string };

/**
 * Saved sections of the current company (palette, inspector and the sections page).
 */
export const usePatternsStore = defineStore('patterns', () => {
    const items = ref<PatternItem[]>([]);
    const loaded = ref(false);

    async function load() {
        items.value = await send<PatternItem[]>(patterns.index());
        loaded.value = true;
    }

    function find(id: unknown) {
        return items.value.find((p) => p.id === id);
    }

    return { items, loaded, load, find };
});
