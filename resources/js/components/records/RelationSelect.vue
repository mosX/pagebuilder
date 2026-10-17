<script setup lang="ts">
import { Rank } from '@element-plus/icons-vue';
import { VueDraggable } from 'vue-draggable-plus';
import { computed, onMounted, ref } from 'vue';
import { send } from '@/lib/http';
import records from '@/routes/api/records';
import type { RelationValue } from '@/types';

const props = defineProps<{
    /** System name of the related collection. */
    collection: string;
    multiple?: boolean;
    /** Records already linked, so their labels show before options load. */
    initial: RelationValue[];
}>();

const model = defineModel<number | number[] | null>({ required: true });

const options = ref<RelationValue[]>([...props.initial]);
const loading = ref(false);
// Titles of every record seen, so selected ones keep their names across searches.
const labels = ref(new Map(props.initial.map((o) => [o.id, o.label])));

async function search(query = '') {
    loading.value = true;

    try {
        const found = await send<RelationValue[]>(
            records.options(props.collection),
            { search: query || undefined },
        );
        found.forEach((o) => labels.value.set(o.id, o.label));
        const known = new Map(
            [...props.initial, ...found].map((o) => [o.id, o]),
        );
        options.value = [...known.values()];
    } finally {
        loading.value = false;
    }
}

onMounted(() => search());

/** Many-to-many links keep the order set here (stored with the relation). */
const ordered = computed({
    get: () =>
        (Array.isArray(model.value) ? model.value : []).map((id) => ({
            id,
            label: labels.value.get(id) ?? `#${id}`,
        })),
    set: (items: RelationValue[]) => {
        model.value = items.map((item) => item.id);
    },
});
</script>

<template>
    <div class="flex w-full flex-col gap-2">
        <el-select
            v-model="model"
            :multiple="multiple"
            filterable
            remote
            clearable
            :remote-method="search"
            :loading="loading"
            placeholder="Начните вводить для поиска"
            class="w-full"
        >
            <el-option
                v-for="option in options"
                :key="option.id"
                :label="option.label"
                :value="option.id"
            />
        </el-select>

        <div v-if="multiple && ordered.length > 1" class="flex flex-col gap-1">
            <span class="text-xs text-[var(--el-text-color-secondary)]">
                Порядок (перетащите):
            </span>
            <VueDraggable
                v-model="ordered"
                :animation="150"
                class="flex flex-col gap-1"
            >
                <div
                    v-for="item in ordered"
                    :key="item.id"
                    class="flex cursor-move items-center gap-2 rounded border border-[var(--el-border-color-lighter)] px-2 py-1 text-sm"
                >
                    <el-icon class="text-[var(--el-text-color-secondary)]">
                        <Rank />
                    </el-icon>
                    {{ item.label }}
                </div>
            </VueDraggable>
        </div>
    </div>
</template>
