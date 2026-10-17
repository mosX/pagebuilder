<script setup lang="ts">
import {
    Bottom,
    Document,
    EditPen,
    Top,
    TrendCharts,
    View,
} from '@element-plus/icons-vue';
import { computed, onMounted, ref } from 'vue';
import { activityActions } from '@/lib/activity-actions';
import { targetLocation } from '@/lib/block-targets';
import { send } from '@/lib/http';
import { dashboard } from '@/routes/api';
import { useSessionStore } from '@/stores/session';

type Day = { date: string; views: number; visitors: number };

type Dashboard = {
    site_url: string;
    pages_collection: string | null;
    members: number;
    tenants: number | null;
    users: number | null;
    visits: {
        days: Day[];
        views: number;
        visitors: number;
        change: number | null;
    } | null;
    submissions: {
        count: number;
        latest: {
            subject: string;
            collection: string | null;
            record: number | null;
            created_at: string | null;
        }[];
    } | null;
    drafts:
        | {
              target: string;
              label: string;
              user: string | null;
              updated_at: string | null;
          }[]
        | null;
    scheduled:
        | {
              title: string;
              collection: string;
              record: number;
              publish_at: string;
          }[]
        | null;
    activity:
        | {
              action: string;
              subject: string;
              actor: string;
              created_at: string | null;
          }[]
        | null;
};

const session = useSessionStore();
const data = ref<Dashboard | null>(null);

onMounted(async () => {
    data.value = await send<Dashboard>(dashboard());
});

const maxViews = computed(() =>
    Math.max(1, ...(data.value?.visits?.days.map((d) => d.views) ?? [])),
);

function when(value: string | null) {
    return value
        ? new Date(value).toLocaleString('ru-RU', {
              day: 'numeric',
              month: 'short',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '';
}
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">
                {{ session.tenant?.current.name }}
            </h1>
            <div class="flex gap-2">
                <el-button
                    v-if="data"
                    :icon="View"
                    tag="a"
                    :href="data.site_url"
                    target="_blank"
                >
                    Открыть сайт
                </el-button>
                <el-button
                    v-if="
                        data?.pages_collection && session.can('content.manage')
                    "
                    :icon="EditPen"
                    @click="
                        $router.push({
                            name: 'records',
                            params: { name: data.pages_collection },
                            query: { new: '1' },
                        })
                    "
                >
                    Новая страница
                </el-button>
            </div>
        </div>

        <el-skeleton v-if="!data" :rows="6" animated />

        <div v-else class="flex flex-col gap-4">
            <div class="grid gap-4 md:grid-cols-3">
                <el-card v-if="data.visits" shadow="never">
                    <div class="flex items-start justify-between">
                        <el-statistic
                            title="Просмотры за 7 дней"
                            :value="data.visits.views"
                        />
                        <el-tag
                            v-if="data.visits.change !== null"
                            :type="
                                data.visits.change >= 0 ? 'success' : 'danger'
                            "
                            size="small"
                        >
                            <el-icon class="align-middle">
                                <Top v-if="data.visits.change >= 0" />
                                <Bottom v-else />
                            </el-icon>
                            {{ Math.abs(data.visits.change) }}%
                        </el-tag>
                    </div>
                    <div class="mt-3 flex h-12 items-end gap-1">
                        <div
                            v-for="day in data.visits.days"
                            :key="day.date"
                            class="min-h-px flex-1 rounded-t bg-[var(--el-color-primary-light-5)]"
                            :style="{
                                height: `${(day.views / maxViews) * 100}%`,
                            }"
                            :title="`${day.date}: ${day.views}`"
                        />
                    </div>
                    <router-link
                        :to="{ name: 'stats' }"
                        class="mt-2 inline-flex items-center gap-1 text-xs text-[var(--el-color-primary)]"
                    >
                        <el-icon><TrendCharts /></el-icon> Вся статистика
                    </router-link>
                </el-card>

                <el-card v-if="data.submissions" shadow="never">
                    <el-statistic
                        title="Заявок с сайта за 7 дней"
                        :value="data.submissions.count"
                    />
                </el-card>

                <el-card shadow="never">
                    <el-statistic
                        title="Пользователей в компании"
                        :value="data.members"
                    />
                    <div
                        v-if="data.tenants !== null"
                        class="mt-2 text-xs text-[var(--el-text-color-secondary)]"
                    >
                        На платформе: {{ data.tenants }} компаний,
                        {{ data.users }} пользователей
                    </div>
                </el-card>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <el-card
                    v-if="data.submissions"
                    shadow="never"
                    header="Новые заявки"
                >
                    <el-empty
                        v-if="!data.submissions.latest.length"
                        :image-size="60"
                        description="За неделю заявок не было"
                    />
                    <ul v-else class="flex flex-col divide-y">
                        <li
                            v-for="(item, index) in data.submissions.latest"
                            :key="index"
                            class="flex justify-between gap-2 py-2 text-sm"
                        >
                            <router-link
                                v-if="item.collection && item.record"
                                :to="{
                                    name: 'records',
                                    params: { name: item.collection },
                                    query: { record: item.record },
                                }"
                                class="text-[var(--el-color-primary)] hover:underline"
                            >
                                {{ item.subject }}
                            </router-link>
                            <span v-else>{{ item.subject }}</span>
                            <span
                                class="shrink-0 text-[var(--el-text-color-secondary)]"
                            >
                                {{ when(item.created_at) }}
                            </span>
                        </li>
                    </ul>
                </el-card>

                <el-card
                    v-if="data.drafts"
                    shadow="never"
                    header="Неопубликованные черновики"
                >
                    <el-empty
                        v-if="!data.drafts.length"
                        :image-size="60"
                        description="Всё опубликовано"
                    />
                    <ul v-else class="flex flex-col divide-y">
                        <li
                            v-for="draft in data.drafts"
                            :key="draft.target"
                            class="flex justify-between gap-2 py-2 text-sm"
                        >
                            <router-link
                                v-if="targetLocation(draft.target)"
                                :to="targetLocation(draft.target)!"
                                class="inline-flex items-center gap-1 text-[var(--el-color-primary)] hover:underline"
                            >
                                <el-icon><Document /></el-icon>
                                {{ draft.label }}
                            </router-link>
                            <span
                                class="shrink-0 text-[var(--el-text-color-secondary)]"
                            >
                                {{ draft.user }} · {{ when(draft.updated_at) }}
                            </span>
                        </li>
                    </ul>
                </el-card>

                <el-card
                    v-if="data.scheduled?.length"
                    shadow="never"
                    header="Запланированные публикации"
                >
                    <ul class="flex flex-col divide-y">
                        <li
                            v-for="item in data.scheduled"
                            :key="`${item.collection}-${item.record}`"
                            class="flex justify-between gap-2 py-2 text-sm"
                        >
                            <router-link
                                :to="{
                                    name: 'records',
                                    params: { name: item.collection },
                                    query: { record: item.record },
                                }"
                                class="text-[var(--el-color-primary)] hover:underline"
                            >
                                {{ item.title }}
                            </router-link>
                            <span
                                class="shrink-0 text-[var(--el-text-color-secondary)]"
                            >
                                {{ when(item.publish_at.replace(' ', 'T')) }}
                            </span>
                        </li>
                    </ul>
                </el-card>

                <el-card
                    v-if="data.activity"
                    shadow="never"
                    header="Последние действия"
                >
                    <el-empty
                        v-if="!data.activity.length"
                        :image-size="60"
                        description="Пока ничего не происходило"
                    />
                    <ul v-else class="flex flex-col divide-y">
                        <li
                            v-for="(item, index) in data.activity"
                            :key="index"
                            class="flex justify-between gap-2 py-2 text-sm"
                        >
                            <span class="min-w-0 truncate">
                                <b>{{ item.actor }}</b
                                >:
                                {{
                                    activityActions[item.action] ?? item.action
                                }}
                                — {{ item.subject }}
                            </span>
                            <span
                                class="shrink-0 text-[var(--el-text-color-secondary)]"
                            >
                                {{ when(item.created_at) }}
                            </span>
                        </li>
                    </ul>
                    <router-link
                        :to="{ name: 'activity' }"
                        class="mt-2 inline-block text-xs text-[var(--el-color-primary)]"
                    >
                        Весь журнал
                    </router-link>
                </el-card>
            </div>
        </div>
    </div>
</template>
