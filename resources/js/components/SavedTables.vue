<script setup>
import { onMounted, ref, watch } from 'vue';
import { api } from '../api';
import Icon from './Icon.vue';

const props = defineProps({ currentId: Number });
const emit = defineEmits(['open', 'deleted', 'unauthorized']);

const tables = ref([]);
const error = ref('');

function handleError(e) {
    if (e.status === 401) return emit('unauthorized');
    error.value = e.message;
}

async function load() {
    try {
        tables.value = (await api('GET', '/tables')).tables;
    } catch (e) {
        handleError(e);
    }
}

async function open(id) {
    error.value = '';

    try {
        emit('open', await api('GET', `/tables/${id}`));
    } catch (e) {
        handleError(e);
    }
}

async function remove(table) {
    if (!confirm(`Delete “${table.title}”?`)) return;
    error.value = '';

    try {
        await api('DELETE', `/tables/${table.id}`);
        tables.value = tables.value.filter((t) => t.id !== table.id);
        emit('deleted', table.id);
    } catch (e) {
        handleError(e);
    }
}

const formatDate = (value) => new Date(value).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });

// A newly built table isn't in the list yet.
watch(() => props.currentId, (id) => {
    if (id && !tables.value.some((t) => t.id === id)) load();
});

onMounted(load);

defineExpose({ load });
</script>

<template>
    <section v-if="tables.length" class="card animate-fade-up overflow-hidden">
        <div class="flex items-center gap-4 border-b border-zinc-950/5 px-5 py-4 sm:px-6">
            <span class="icon-badge bg-violet-50 text-violet-600 ring-violet-600/10">
                <Icon name="table" class="size-5" />
            </span>
            <div>
                <h3 class="font-semibold text-zinc-950">Saved tables</h3>
                <p class="text-sm text-zinc-500">{{ tables.length }} table{{ tables.length === 1 ? '' : 's' }} built by Claude</p>
            </div>
        </div>

        <div v-if="error" class="mx-5 mt-4 flex gap-3 rounded-xl bg-red-50 p-3.5 text-sm text-red-700 ring-1 ring-red-600/10 sm:mx-6">
            <Icon name="warning" class="size-5 shrink-0" />
            <span>{{ error }}</span>
        </div>

        <ul class="scroll-thin max-h-72 divide-y divide-zinc-950/5 overflow-y-auto text-sm">
            <li v-for="table in tables" :key="table.id"
                class="group relative flex items-center gap-3 px-5 py-3 transition-colors hover:bg-zinc-50 sm:px-6"
                :class="{ 'bg-violet-50/60 hover:bg-violet-50': table.id === currentId }">
                <span v-if="table.id === currentId" class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-violet-600"></span>
                <button @click="open(table.id)" class="min-w-0 flex-1 text-left" :title="table.request">
                    <span class="block truncate font-medium text-zinc-900 group-hover:text-violet-700">{{ table.title }}</span>
                    <span class="block truncate text-xs text-zinc-500">{{ table.request }}</span>
                </button>
                <span class="hidden text-xs whitespace-nowrap text-zinc-400 tabular-nums sm:block">{{ formatDate(table.created_at) }}</span>
                <div class="flex items-center gap-1">
                    <a :href="`/tables/${table.id}/download/xlsx`"
                       class="rounded-lg px-2 py-1 text-xs font-medium text-emerald-700 transition hover:bg-emerald-50">Excel</a>
                    <a :href="`/tables/${table.id}/download/pdf`"
                       class="rounded-lg px-2 py-1 text-xs font-medium text-rose-600 transition hover:bg-rose-50">PDF</a>
                    <button @click="remove(table)" title="Delete table"
                            class="rounded-lg p-1.5 text-zinc-400 transition hover:bg-red-50 hover:text-red-600">
                        <Icon name="trash" class="size-4" />
                        <span class="sr-only">Delete table</span>
                    </button>
                </div>
            </li>
        </ul>
    </section>
</template>
