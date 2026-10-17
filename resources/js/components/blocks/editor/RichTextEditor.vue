<script setup lang="ts">
import StarterKit from '@tiptap/starter-kit';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import { ElMessageBox } from 'element-plus';
import { onBeforeUnmount, watch } from 'vue';
import { toDoc } from '@/components/blocks/RichText';

/**
 * Formatted text input for block settings. The value is a Tiptap JSON document;
 * the server keeps only the formats offered here.
 */
const model = defineModel<unknown>({ required: true });

const editor = useEditor({
    content: toDoc(model.value),
    extensions: [
        StarterKit.configure({
            heading: { levels: [2, 3, 4] },
            codeBlock: false,
            link: {
                openOnClick: false,
                autolink: true,
                protocols: ['mailto', 'tel'],
            },
        }),
    ],
    editorProps: {
        attributes: {
            class: 'min-h-32 px-3 py-2 outline-none [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5 [&_h2]:text-lg [&_h2]:font-bold [&_h3]:font-semibold [&_h4]:font-semibold [&_blockquote]:border-l-4 [&_blockquote]:pl-3 [&_a]:text-blue-600 [&_a]:underline [&_p]:my-1',
        },
    },
    onUpdate: ({ editor }) => {
        model.value = editor.getJSON();
    },
});

// Another block selected in the inspector: show its text.
watch(model, (value) => {
    const instance = editor.value;

    if (
        instance &&
        JSON.stringify(instance.getJSON()) !== JSON.stringify(toDoc(value))
    ) {
        instance.commands.setContent(toDoc(value), { emitUpdate: false });
    }
});

onBeforeUnmount(() => editor.value?.destroy());

async function setLink() {
    const instance = editor.value;

    if (!instance) {
        return;
    }

    try {
        const { value } = await ElMessageBox.prompt('Адрес ссылки', 'Ссылка', {
            inputValue: instance.getAttributes('link').href ?? 'https://',
            confirmButtonText: 'Готово',
            cancelButtonText: 'Отмена',
        });
        const chain = instance.chain().focus().extendMarkRange('link');
        if (value) {
            chain.setLink({ href: value }).run();
        } else {
            chain.unsetLink().run();
        }
    } catch {
        // Cancelled.
    }
}

type Tool = {
    label: string;
    title: string;
    active: string | [string, object];
    run: () => void;
};

const tools = (): Tool[] => {
    const chain = () => editor.value!.chain().focus();

    return [
        {
            label: 'B',
            title: 'Жирный',
            active: 'bold',
            run: () => chain().toggleBold().run(),
        },
        {
            label: 'I',
            title: 'Курсив',
            active: 'italic',
            run: () => chain().toggleItalic().run(),
        },
        {
            label: 'U',
            title: 'Подчёркнутый',
            active: 'underline',
            run: () => chain().toggleUnderline().run(),
        },
        {
            label: 'H2',
            title: 'Заголовок',
            active: ['heading', { level: 2 }],
            run: () => chain().toggleHeading({ level: 2 }).run(),
        },
        {
            label: 'H3',
            title: 'Подзаголовок',
            active: ['heading', { level: 3 }],
            run: () => chain().toggleHeading({ level: 3 }).run(),
        },
        {
            label: '•',
            title: 'Список',
            active: 'bulletList',
            run: () => chain().toggleBulletList().run(),
        },
        {
            label: '1.',
            title: 'Нумерованный список',
            active: 'orderedList',
            run: () => chain().toggleOrderedList().run(),
        },
        {
            label: '❝',
            title: 'Цитата',
            active: 'blockquote',
            run: () => chain().toggleBlockquote().run(),
        },
        {
            label: '🔗',
            title: 'Ссылка',
            active: 'link',
            run: () => void setLink(),
        },
    ];
};

function isActive(tool: Tool) {
    const instance = editor.value;

    return Array.isArray(tool.active)
        ? instance?.isActive(tool.active[0], tool.active[1])
        : instance?.isActive(tool.active);
}
</script>

<template>
    <div
        class="w-full overflow-hidden rounded border border-[var(--el-border-color)] bg-white focus-within:border-[var(--el-color-primary)]"
    >
        <div
            v-if="editor"
            class="flex flex-wrap gap-0.5 border-b border-[var(--el-border-color-lighter)] bg-[var(--el-fill-color-lighter)] p-1"
        >
            <button
                v-for="tool in tools()"
                :key="tool.title"
                type="button"
                :title="tool.title"
                class="min-w-7 rounded px-1.5 py-0.5 text-xs font-medium hover:bg-[var(--el-fill-color)]"
                :class="{
                    'bg-[var(--el-color-primary-light-8)] text-[var(--el-color-primary)]':
                        isActive(tool),
                }"
                @mousedown.prevent
                @click="tool.run()"
            >
                {{ tool.label }}
            </button>
        </div>
        <EditorContent :editor="editor" class="text-sm" />
    </div>
</template>
