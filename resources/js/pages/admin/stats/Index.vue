<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { send } from '@/lib/http';
import { siteStats } from '@/routes/api';
import { useSessionStore } from '@/stores/session';

type Stats = {
    period: number;
    days: { date: string; views: number; visitors: number }[];
    pages: { path: string; views: number; visitors: number }[];
    referrers: { host: string; views: number }[];
    totals: { views: number; visitors: number };
};

const session = useSessionStore();
const stats = ref<Stats | null>(null);
const period = ref(30);
const loading = ref(true);

async function load() {
    loading.value = true;

    try {
        stats.value = await send<Stats>(siteStats(), { days: period.value });
    } finally {
        loading.value = false;
    }
}

onMounted(load);

const max = computed(() =>
    Math.max(1, ...(stats.value?.days.map((d) => d.views) ?? [])),
);

const siteUrl = computed(() => session.tenant?.current.site_url ?? '');

function label(date: string) {
    const [, month, day] = date.split('-');

    return `${day}.${month}`;
}
</script>

<template>
    <div v-loading="loading">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Статистика сайта</h1>
                <p class="text-sm text-[var(--el-text-color-secondary)]">
                    Просмотры страниц без cookie и сторонних счётчиков. Боты и
                    превью не учитываются.
                </p>
            </div>
            <el-radio-group v-model="period" @change="load">
                <el-radio-button :value="7">7 дней</el-radio-button>
                <el-radio-button :value="30">30 дней</el-radio-button>
                <el-radio-button :value="90">90 дней</el-radio-button>
            </el-radio-group>
        </div>

        <div class="mb-4 grid gap-4 sm:grid-cols-2">
            <el-card shadow="never">
                <div class="text-sm text-[var(--el-text-color-secondary)]">
                    Просмотры
                </div>
                <div class="text-3xl font-semibold">
                    {{ stats?.totals.views.toLocaleString('ru-RU') ?? 0 }}
                </div>
            </el-card>
            <el-card shadow="never">
                <div class="text-sm text-[var(--el-text-color-secondary)]">
                    Посетители
                    <span class="text-xs"
                        >(сумма уникальных за каждый день)</span
                    >
                </div>
                <div class="text-3xl font-semibold">
                    {{ stats?.totals.visitors.toLocaleString('ru-RU') ?? 0 }}
                </div>
            </el-card>
        </div>

        <el-card shadow="never" header="По дням" class="mb-4">
            <div class="flex h-48 items-end gap-px">
                <el-tooltip
                    v-for="day in stats?.days ?? []"
                    :key="day.date"
                    :content="`${label(day.date)}: ${day.views} просмотров, ${day.visitors} посетителей`"
                    placement="top"
                >
                    <div class="flex h-full flex-1 flex-col justify-end">
                        <div
                            class="min-h-px rounded-t bg-[var(--el-color-primary-light-5)] hover:bg-[var(--el-color-primary)]"
                            :style="{ height: `${(day.views / max) * 100}%` }"
                        />
                    </div>
                </el-tooltip>
            </div>
            <div
                v-if="stats?.days.length"
                class="mt-2 flex justify-between text-xs text-[var(--el-text-color-secondary)]"
            >
                <span>{{ label(stats.days[0].date) }}</span>
                <span>{{ label(stats.days[stats.days.length - 1].date) }}</span>
            </div>
        </el-card>

        <div class="grid gap-4 lg:grid-cols-2">
            <el-card
                shadow="never"
                header="Популярные страницы"
                body-class="!p-0"
            >
                <el-table
                    :data="stats?.pages ?? []"
                    empty-text="Пока нет данных"
                >
                    <el-table-column label="Страница" min-width="200">
                        <template #default="{ row }">
                            <a
                                :href="`${siteUrl}${row.path === '/' ? '' : row.path}`"
                                target="_blank"
                                class="text-[var(--el-color-primary)] hover:underline"
                            >
                                {{ row.path }}
                            </a>
                        </template>
                    </el-table-column>
                    <el-table-column
                        prop="views"
                        label="Просмотры"
                        width="110"
                        align="right"
                    />
                    <el-table-column
                        prop="visitors"
                        label="Посетители"
                        width="110"
                        align="right"
                    />
                </el-table>
            </el-card>
            <el-card shadow="never" header="Откуда приходят" body-class="!p-0">
                <el-table
                    :data="stats?.referrers ?? []"
                    empty-text="Переходов с других сайтов пока не было"
                >
                    <el-table-column prop="host" label="Сайт" min-width="200" />
                    <el-table-column
                        prop="views"
                        label="Переходы"
                        width="110"
                        align="right"
                    />
                </el-table>
            </el-card>
        </div>
    </div>
</template>
