<script setup lang="ts">
defineProps<{
    block: {
        height: number;
    };
}>();
</script>

<template>
    <div :style="{ height: `${block.height}px` }" aria-hidden="true" />
</template>
