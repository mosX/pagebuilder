<script setup lang="ts">
import { ArrowDown, ArrowUp, Delete, Plus } from '@element-plus/icons-vue';
import { computed, defineAsyncComponent, ref } from 'vue';
import type { PropSchema } from '@/blocks/registry';

// PropInput renders ListInput for list props: load it lazily to break the import cycle.
const PropInput = defineAsyncComponent(
    () => import('@/components/blocks/editor/PropInput.vue'),
);

/**
 * Editor of a repeatable group of props (FAQ items, plans, gallery images).
 */
const props = defineProps<{ schema: PropSchema }>();
const model = defineModel<unknown>({ required: true });

const items = computed<Record<string, unknown>[]>(() =>
    Array.isArray(model.value)
        ? (model.value as Record<string, unknown>[])
        : [],
);
const itemSchema = computed(() => props.schema.item ?? {});
const open = ref<number[]>([]);

function update(next: Record<string, unknown>[]) {
    model.value = next;
}

function add() {
    const item = Object.fromEntries(
        Object.entries(itemSchema.value).map(([key, prop]) => [
            key,
            structuredClone(prop.default ?? null),
        ]),
    );
    update([...items.value, item]);
    open.value = [items.value.length - 1];
}

function remove(index: number) {
    update(items.value.filter((_, i) => i !== index));
}

function move(index: number, delta: number) {
    const next = [...items.value];
    const target = index + delta;

    if (target < 0 || target >= next.length) {
        return;
    }

    [next[index], next[target]] = [next[target], next[index]];
    update(next);
}

function setField(index: number, key: string, value: unknown) {
    update(
        items.value.map((item, i) =>
            i === index ? { ...item, [key]: value } : item,
        ),
    );
}

function title(item: Record<string, unknown>, index: number) {
    const label = props.schema.itemLabel ? item[props.schema.itemLabel] : null;

    return typeof label === 'string' && label ? label : `Элемент ${index + 1}`;
}
</script>

<template>
    <div class="flex w-full flex-col gap-2">
        <el-collapse v-model="open">
            <el-collapse-item
                v-for="(item, index) in items"
                :key="index"
                :name="index"
            >
                <template #title>
                    <div class="flex min-w-0 flex-1 items-center gap-1 pr-2">
                        <span class="truncate text-xs">{{
                            title(item, index)
                        }}</span>
                        <span class="flex-1" />
                        <el-button
                            :icon="ArrowUp"
                            link
                            size="small"
                            :disabled="index === 0"
                            @click.stop="move(index, -1)"
                        />
                        <el-button
                            :icon="ArrowDown"
                            link
                            size="small"
                            :disabled="index === items.length - 1"
                            @click.stop="move(index, 1)"
                        />
                        <el-button
                            :icon="Delete"
                            link
                            size="small"
                            type="danger"
                            @click.stop="remove(index)"
                        />
                    </div>
                </template>
                <div class="flex flex-col gap-2">
                    <div
                        v-for="(prop, key) in itemSchema"
                        :key="key"
                        class="flex flex-col gap-1"
                    >
                        <span
                            class="text-xs text-[var(--el-text-color-secondary)]"
                        >
                            {{ prop.label }}
                        </span>
                        <PropInput
                            :model-value="item[key]"
                            :schema="prop"
                            :siblings="item"
                            @update:model-value="
                                (value: unknown) =>
                                    setField(index, String(key), value)
                            "
                        />
                    </div>
                </div>
            </el-collapse-item>
        </el-collapse>
        <el-button
            size="small"
            :icon="Plus"
            :disabled="items.length >= (schema.max ?? 50)"
            @click="add"
        >
            Добавить
        </el-button>
    </div>
</template>
