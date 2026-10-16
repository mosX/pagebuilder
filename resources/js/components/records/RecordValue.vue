<script setup lang="ts">
import { computed } from 'vue';
import type { CollectionField, RecordValue, RelationValue } from '@/types';

const props = defineProps<{
    field: CollectionField;
    value: RecordValue;
}>();

const relations = computed<RelationValue[]>(() => {
    if (Array.isArray(props.value)) {
        return props.value as RelationValue[];
    }

    return props.value && typeof props.value === 'object' ? [props.value] : [];
});

const text = computed(() => {
    const value = props.value;

    if (value === null || value === '') {
        return '—';
    }

    if (props.field.type === 'date' && typeof value === 'string') {
        return value.split('-').reverse().join('.');
    }

    if (props.field.type === 'datetime' && typeof value === 'string') {
        return new Date(value.replace(' ', 'T')).toLocaleString('ru-RU', {
            dateStyle: 'short',
            timeStyle: 'short',
        });
    }

    const plain = String(value);

    return plain.length > 80 ? `${plain.slice(0, 80)}…` : plain;
});
</script>

<template>
    <span
        v-if="field.type === 'blocks'"
        class="text-[var(--el-text-color-secondary)]"
    >
        {{ Array.isArray(value) ? `Блоков: ${value.length}` : '—' }}
    </span>
    <img
        v-else-if="field.type === 'image' && value"
        :src="String(value)"
        alt=""
        class="h-10 w-16 rounded object-cover"
    />
    <span v-else-if="field.type === 'boolean'">
        <el-tag :type="value ? 'success' : 'info'" size="small">
            {{ value ? 'Да' : 'Нет' }}
        </el-tag>
    </span>
    <span v-else-if="field.related_collection">
        <el-tag
            v-for="item in relations"
            :key="item.id"
            size="small"
            class="mr-1"
        >
            {{ item.label }}
        </el-tag>
        <span v-if="!relations.length">—</span>
    </span>
    <a
        v-else-if="field.type === 'url' && value"
        :href="String(value)"
        target="_blank"
        rel="noopener"
        class="text-[var(--el-color-primary)]"
    >
        {{ text }}
    </a>
    <span v-else>{{ text }}</span>
</template>
