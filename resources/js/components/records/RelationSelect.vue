<script setup lang="ts">
import { onMounted, ref } from 'vue';
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

async function search(query = '') {
    loading.value = true;

    try {
        const found = await send<RelationValue[]>(
            records.options(props.collection),
            { search: query || undefined },
        );
        const known = new Map(
            [...props.initial, ...found].map((o) => [o.id, o]),
        );
        options.value = [...known.values()];
    } finally {
        loading.value = false;
    }
}

onMounted(() => search());
</script>

<template>
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
</template>
