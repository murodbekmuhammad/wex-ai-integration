<script setup>
import { computed, onMounted, ref } from 'vue';
import { api } from '../api';
import AgentCard from './AgentCard.vue';
import AppHeader from './AppHeader.vue';
import Icon from './Icon.vue';
import Spinner from './Spinner.vue';

const props = defineProps({ user: Object });
const emit = defineEmits(['logout']);

const agents = ref([]);
const loading = ref(true);
const error = ref('');

const greeting = computed(() => {
    const hour = new Date().getHours();
    const part = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
    const firstName = (props.user.name || '').split(' ')[0];

    return firstName ? `${part}, ${firstName}` : part;
});

onMounted(async () => {
    try {
        agents.value = (await api('GET', '/agents')).agents;
    } catch (e) {
        if (e.status === 401) return emit('logout');
        error.value = e.message;
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div class="relative min-h-screen">
        <div class="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[34rem] overflow-hidden" aria-hidden="true">
            <div class="absolute -top-48 left-1/4 size-[36rem] rounded-full bg-violet-300/30 blur-3xl"></div>
            <div class="absolute -top-32 right-0 size-[30rem] rounded-full bg-sky-200/40 blur-3xl"></div>
        </div>

        <AppHeader :user="user" page="agents" @logout="emit('logout')" />

        <main class="mx-auto max-w-7xl space-y-8 px-4 py-10 sm:px-6">
            <div class="animate-fade-up">
                <h1 class="text-3xl font-semibold tracking-tight text-zinc-950 sm:text-4xl">{{ greeting }}</h1>
                <p class="mt-2 text-zinc-500">Pick an agent and run it. Each one reads your PDFs from Gmail and builds its report for you.</p>
            </div>

            <div v-if="loading" class="flex items-center gap-2 text-sm text-zinc-500">
                <Spinner class="size-4 text-violet-600" /> Loading agents…
            </div>

            <div v-else-if="error" class="flex gap-3 rounded-xl bg-red-50 p-3.5 text-sm text-red-700 ring-1 ring-red-600/10">
                <Icon name="warning" class="size-5 shrink-0" />
                <span>{{ error }}</span>
            </div>

            <div v-else-if="!agents.length" class="card flex items-start gap-4 p-5 sm:p-6">
                <span class="icon-badge bg-amber-50 text-amber-600 ring-amber-600/10">
                    <Icon name="warning" class="size-5" />
                </span>
                <div class="text-sm">
                    <p class="font-medium text-zinc-950">No agents found</p>
                    <p class="mt-1 text-zinc-500">
                        Agents are listed in <code>config/agents.php</code>. If that file is on the server,
                        refresh the cached config with <code>php artisan config:cache</code> and reload this page.
                    </p>
                </div>
            </div>

            <div v-else class="grid animate-fade-up items-start gap-6 lg:grid-cols-2">
                <AgentCard v-for="agent in agents" :key="agent.key" :agent="agent" @unauthorized="emit('logout')" />
            </div>
        </main>
    </div>
</template>
