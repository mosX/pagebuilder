<script setup lang="ts">
import { ElMessage } from 'element-plus';
import { computed, ref, watch } from 'vue';
import { useForm } from '@/composables/useForm';
import { toIdentifier } from '@/lib/identifier';
import fields from '@/routes/api/collections/fields';
import type {
    CollectionField,
    CollectionRef,
    CollectionSchema,
    FieldTypeOption,
    FieldTypeValue,
} from '@/types';

const props = defineProps<{
    collection: CollectionSchema;
    fieldTypes: FieldTypeOption[];
    collections: CollectionRef[];
    /** The field being edited, or null to add a new one. */
    field: CollectionField | null;
}>();

const open = defineModel<boolean>({ required: true });
const emit = defineEmits<{ saved: [] }>();

const isEdit = computed(() => props.field !== null);
// System fields are used by the platform; only their label can change.
const isSystem = computed(() => props.field?.is_system ?? false);
const nameTouched = ref(false);

const form = useForm({
    label: '',
    name: '',
    type: 'string' as FieldTypeValue,
    is_required: false,
    is_unique: false,
    options: { choices: [] as string[], scale: 2 },
    related_collection_id: null as number | null,
});

const typeGroups = computed(() => {
    const groups = new Map<string, FieldTypeOption[]>();

    for (const type of props.fieldTypes) {
        groups.set(type.group, [...(groups.get(type.group) ?? []), type]);
    }

    return [...groups.entries()].map(([label, options]) => ({
        label,
        options,
    }));
});

const selectedType = computed(() =>
    props.fieldTypes.find((t) => t.value === form.type),
);

watch(open, (value) => {
    if (!value) {
        return;
    }

    form.clearErrors();
    nameTouched.value = isEdit.value;

    const field = props.field;
    form.label = field?.label ?? '';
    form.name = field?.name ?? '';
    form.type = field?.type ?? 'string';
    form.is_required = field?.is_required ?? false;
    form.is_unique = field?.is_unique ?? false;
    form.options = {
        choices: [...(field?.options.choices ?? [])],
        scale: field?.options.scale ?? 2,
    };
    form.related_collection_id = field?.related_collection?.id ?? null;
});

watch(
    () => form.label,
    (label) => {
        if (!nameTouched.value) {
            form.name = toIdentifier(label);
        }
    },
);

async function save() {
    const route = props.field
        ? fields.update({
              collection: props.collection.name,
              field: props.field.id,
          })
        : fields.store(props.collection.name);

    const result = await form.submit<{ message: string }>(route);

    if (result) {
        ElMessage.success(result.message);
        open.value = false;
        emit('saved');
    }
}
</script>

<template>
    <el-dialog
        v-model="open"
        :title="isEdit ? 'Настройки поля' : 'Новое поле'"
        width="520"
    >
        <el-form label-position="top" @submit.prevent="save">
            <el-form-item label="Название" :error="form.errors.label">
                <el-input v-model="form.label" placeholder="Цена" />
            </el-form-item>

            <el-form-item label="Системное имя" :error="form.errors.name">
                <el-input
                    v-model="form.name"
                    :disabled="isEdit"
                    placeholder="price"
                    @input="nameTouched = true"
                />
            </el-form-item>

            <el-form-item label="Тип" :error="form.errors.type">
                <el-select v-model="form.type" :disabled="isEdit">
                    <el-option-group
                        v-for="group in typeGroups"
                        :key="group.label"
                        :label="group.label"
                    >
                        <el-option
                            v-for="type in group.options"
                            :key="type.value"
                            :label="type.label"
                            :value="type.value"
                        />
                    </el-option-group>
                </el-select>
                <div
                    v-if="isEdit"
                    class="text-xs text-[var(--el-text-color-secondary)]"
                >
                    Системное имя и тип нельзя изменить: это колонка в таблице.
                    Удалите поле и создайте новое.
                </div>
            </el-form-item>

            <el-form-item
                v-if="selectedType?.is_relation"
                label="Связанная коллекция"
                :error="form.errors.related_collection_id"
            >
                <el-select
                    v-model="form.related_collection_id"
                    :disabled="isEdit"
                    placeholder="Выберите коллекцию"
                >
                    <el-option
                        v-for="c in collections"
                        :key="c.id"
                        :label="c.label"
                        :value="c.id"
                    />
                </el-select>
            </el-form-item>

            <el-form-item
                v-if="form.type === 'select'"
                label="Варианты"
                :error="form.errors['options.choices']"
            >
                <el-select
                    v-model="form.options.choices"
                    :disabled="isSystem"
                    multiple
                    filterable
                    allow-create
                    default-first-option
                    :reserve-keyword="false"
                    placeholder="Введите вариант и нажмите Enter"
                />
            </el-form-item>

            <el-form-item
                v-if="form.type === 'decimal'"
                label="Знаков после запятой"
                :error="form.errors['options.scale']"
            >
                <el-input-number
                    v-model="form.options.scale"
                    :min="0"
                    :max="8"
                    :disabled="isEdit"
                />
            </el-form-item>

            <div class="flex gap-6">
                <el-checkbox v-model="form.is_required" :disabled="isSystem">
                    Обязательное
                </el-checkbox>
                <el-checkbox
                    v-if="selectedType?.supports_unique"
                    v-model="form.is_unique"
                    :disabled="isSystem"
                >
                    Уникальное
                </el-checkbox>
            </div>
            <div
                v-if="form.errors.is_unique"
                class="mt-1 text-xs text-[var(--el-color-danger)]"
            >
                {{ form.errors.is_unique }}
            </div>
        </el-form>

        <template #footer>
            <el-button @click="open = false">Отмена</el-button>
            <el-button type="primary" :loading="form.processing" @click="save">
                {{ isEdit ? 'Сохранить' : 'Добавить' }}
            </el-button>
        </template>
    </el-dialog>
</template>
