<script setup lang="ts">
import { Document, Picture } from '@element-plus/icons-vue';
import { computed, ref } from 'vue';
import MediaPicker from '@/components/media/MediaPicker.vue';
import RelationSelect from '@/components/records/RelationSelect.vue';
import type { CollectionField, RelationValue } from '@/types';

defineProps<{
    field: CollectionField;
    /** Linked records of a relation field (for labels). */
    related?: RelationValue[];
    /** Download link of a file a visitor attached (private, admin only). */
    downloadUrl?: string | null;
}>();

// Value shape depends on the field type: scalar, relation id, or list of ids.
const model = defineModel<unknown>({ required: true });
const pickerOpen = ref(false);

/** Files from site forms are private: "private://…/name.pdf". */
const privateFile = computed(() =>
    typeof model.value === 'string' && model.value.startsWith('private://')
        ? model.value.split('/').pop()
        : null,
);
</script>

<template>
    <el-input
        v-if="
            field.type === 'string' ||
            field.type === 'email' ||
            field.type === 'url'
        "
        v-model="model as string"
        :type="field.type === 'email' ? 'email' : 'text'"
        clearable
    />
    <div v-else-if="field.type === 'image'" class="flex w-full flex-col gap-2">
        <img
            v-if="model"
            :src="String(model)"
            alt=""
            class="max-h-40 w-fit rounded bg-gray-100 object-contain"
        />
        <div class="flex gap-2">
            <el-button :icon="Picture" @click="pickerOpen = true">
                Выбрать из медиатеки
            </el-button>
            <el-button v-if="model" text @click="model = null"
                >Убрать</el-button
            >
        </div>
        <MediaPicker
            v-model="pickerOpen"
            :selected-url="String(model ?? '')"
            @pick="(item) => (model = item.url)"
        />
    </div>
    <div v-else-if="field.type === 'file'" class="flex w-full flex-col gap-2">
        <div v-if="privateFile" class="flex items-center gap-2">
            <a
                :href="downloadUrl ?? undefined"
                class="inline-flex items-center gap-1 text-[var(--el-color-primary)] hover:underline"
            >
                <el-icon><Document /></el-icon> {{ privateFile }}
            </a>
            <span class="text-xs text-[var(--el-text-color-secondary)]"
                >из формы на сайте</span
            >
        </div>
        <template v-else>
            <div class="flex gap-2">
                <el-button :icon="Document" @click="pickerOpen = true">
                    Выбрать документ
                </el-button>
                <el-button v-if="model" text @click="model = null"
                    >Убрать</el-button
                >
            </div>
            <el-input
                v-model="model as string"
                placeholder="или адрес https://…"
                clearable
            />
            <MediaPicker
                v-model="pickerOpen"
                kind="document"
                :selected-url="String(model ?? '')"
                @pick="(item) => (model = item.url)"
            />
        </template>
    </div>
    <el-input
        v-else-if="field.type === 'text'"
        v-model="model as string"
        type="textarea"
        :autosize="{ minRows: 3, maxRows: 12 }"
    />
    <el-input-number
        v-else-if="field.type === 'integer'"
        v-model="model as number"
        :precision="0"
        :value-on-clear="null"
        controls-position="right"
        class="!w-56"
    />
    <el-input-number
        v-else-if="field.type === 'decimal'"
        v-model="model as number"
        :precision="field.options.scale ?? 2"
        :value-on-clear="null"
        controls-position="right"
        class="!w-56"
    />
    <el-switch
        v-else-if="field.type === 'boolean'"
        v-model="model as boolean"
    />
    <el-date-picker
        v-else-if="field.type === 'date'"
        v-model="model as string"
        type="date"
        value-format="YYYY-MM-DD"
        format="DD.MM.YYYY"
    />
    <el-date-picker
        v-else-if="field.type === 'datetime'"
        v-model="model as string"
        type="datetime"
        value-format="YYYY-MM-DD HH:mm:ss"
        format="DD.MM.YYYY HH:mm"
    />
    <el-select
        v-else-if="field.type === 'select'"
        v-model="model as string"
        clearable
        class="w-full"
    >
        <el-option
            v-for="choice in field.options.choices ?? []"
            :key="choice"
            :label="choice"
            :value="choice"
        />
    </el-select>
    <RelationSelect
        v-else-if="field.related_collection"
        v-model="model as number | number[] | null"
        :collection="field.related_collection.name"
        :multiple="field.type === 'belongs_to_many'"
        :initial="related ?? []"
    />
</template>
