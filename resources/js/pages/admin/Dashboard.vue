<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { send } from '@/lib/http';
import { dashboard } from '@/routes/api';
import { useSessionStore } from '@/stores/session';

type Stats = {
    members: number;
    tenants: number | null;
    users: number | null;
};

const session = useSessionStore();
const stats = ref<Stats | null>(null);

onMounted(async () => {
    stats.value = await send<Stats>(dashboard());
});
</script>

<template>
    <div>
        <h1 class="mb-4 text-xl font-semibold">
            {{ session.tenant?.current.name }}
        </h1>

        <el-skeleton v-if="!stats" :rows="2" animated />
        <el-row v-else :gutter="16">
            <el-col :xs="24" :sm="8">
                <el-card shadow="never">
                    <el-statistic
                        title="Пользователей в компании"
                        :value="stats.members"
                    />
                </el-card>
            </el-col>
            <el-col v-if="stats.tenants !== null" :xs="24" :sm="8">
                <el-card shadow="never">
                    <el-statistic
                        title="Компаний на платформе"
                        :value="stats.tenants"
                    />
                </el-card>
            </el-col>
            <el-col v-if="stats.users !== null" :xs="24" :sm="8">
                <el-card shadow="never">
                    <el-statistic
                        title="Пользователей на платформе"
                        :value="stats.users"
                    />
                </el-card>
            </el-col>
        </el-row>
    </div>
</template>
