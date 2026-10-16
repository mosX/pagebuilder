<script setup lang="ts">
import { ElMessage } from 'element-plus';
import { computed, shallowRef, watch } from 'vue';
import { useRouter } from 'vue-router';
import FieldInput from '@/components/records/FieldInput.vue';
import { useForm } from '@/composables/useForm';
import records from '@/routes/api/records';
import type {
    CollectionField,
    CollectionRecord,
    CollectionSchema,
    RelationValue,
} from '@/types';

const props = defineProps<{
    collection: CollectionSchema;
    /** Record to edit, or null to create a new one. */
    record: CollectionRecord | null;
    readonly?: boolean;
}>();

const open = defineModel<boolean>({ required: true });
const emit = defineEmits<{ saved: [] }>();
const router = useRouter();

function openConstructor(field: string) {
    if (props.record) {
        void router.push({
            name: 'blocks.editor',
            params: { name: props.collection.name, id: props.record.id, field },
        });
    }
}

/**
 * Converts a stored value into what the input edits: relations become ids.
 */
function toInput(field: CollectionField, record: CollectionRecord | null) {
    const value = record?.[field.name] ?? null;

    if (field.type === 'belongs_to_many') {
        return Array.isArray(value) ? value.map((v) => v.id) : [];
    }

    if (field.type === 'belongs_to') {
        return value && typeof value === 'object' && 'id' in value
            ? value.id
            : null;
    }

    if (field.type === 'boolean') {
        return Boolean(value);
    }

    if (field.type === 'decimal' && value !== null) {
        return Number(value);
    }

    return value;
}

function related(field: CollectionField): RelationValue[] {
    const value = props.record?.[field.name];

    if (!field.related_collection) {
        return [];
    }

    if (Array.isArray(value)) {
        return value as RelationValue[];
    }

    return value && typeof value === 'object' ? [value] : [];
}

// Fields are defined by the collection, so the form is rebuilt for every opening.
const form = shallowRef(useForm<Record<string, unknown>>({}));

watch(open, (value) => {
    if (value) {
        form.value = useForm(
            Object.fromEntries(
                // Block trees are edited in the page constructor, not in this form.
                props.collection.fields
                    .filter((f) => f.type !== 'blocks')
                    .map((f) => [f.name, toInput(f, props.record)]),
            ),
        );
    }
});

const title = computed(() =>
    props.record ? `Запись #${props.record.id}` : 'Новая запись',
);

async function save() {
    const route = props.record
        ? records.update({
              collection: props.collection.name,
              record: props.record.id,
          })
        : records.store(props.collection.name);

    const result = await form.value.submit<{ message: string }>(route);

    if (result) {
        ElMessage.success(result.message);
        open.value = false;
        emit('saved');
    }
}
</script>

<template>
    <el-drawer v-model="open" :title="title" size="560px" destroy-on-close>
        <el-form
            label-position="top"
            :disabled="readonly"
            @submit.prevent="save"
        >
            <el-form-item
                v-for="field in collection.fields"
                :key="field.id"
                :label="field.label"
                :required="field.is_required"
                :error="form.errors[field.name]"
            >
                <template v-if="field.type === 'blocks'">
                    <el-button
                        v-if="record && !readonly"
                        type="primary"
                        plain
                        @click="openConstructor(field.name)"
                    >
                        Открыть конструктор
                    </el-button>
                    <span
                        v-else-if="!record"
                        class="text-xs text-[var(--el-text-color-secondary)]"
                    >
                        Сохраните запись, чтобы открыть конструктор блоков.
                    </span>
                </template>
                <FieldInput
                    v-else
                    v-model="form[field.name]"
                    :field="field"
                    :related="related(field)"
                />
            </el-form-item>
        </el-form>

        <template #footer>
            <el-button @click="open = false">
                {{ readonly ? 'Закрыть' : 'Отмена' }}
            </el-button>
            <el-button
                v-if="!readonly"
                type="primary"
                :loading="form.processing"
                @click="save"
            >
                Сохранить
            </el-button>
        </template>
    </el-drawer>
</template>
