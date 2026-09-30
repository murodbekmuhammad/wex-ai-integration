<script setup>
import { useAgentRun } from '../useAgentRun';
import AgentResult from './AgentResult.vue';
import Icon from './Icon.vue';
import Spinner from './Spinner.vue';

const props = defineProps({ agent: Object });
const emit = defineEmits(['unauthorized']);

const { log, running, error, sheetUrl, start } = useAgentRun(() => emit('unauthorized'));

const run = () => start(`/agents/${props.agent.key}/run`);
</script>

<template>
    <article class="card flex flex-col overflow-hidden">
        <div class="flex items-start gap-4 p-5 sm:p-6">
            <span class="icon-badge bg-violet-50 text-violet-600 ring-violet-600/10">
                <Icon name="table" class="size-5" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="font-semibold text-zinc-950">{{ agent.name }}</h2>
                <p class="mt-1 text-sm text-zinc-500">{{ agent.description }}</p>
            </div>
        </div>

        <div class="flex items-center gap-3 border-t border-zinc-950/5 px-5 py-3 sm:px-6">
            <p class="mr-auto text-xs text-zinc-500">
                <template v-if="running">Working… this usually takes about a minute.</template>
                <template v-else-if="sheetUrl">Finished. Run again for a fresh report.</template>
                <template v-else>Creates a new Google Sheet each run.</template>
            </p>
            <button @click="run" :disabled="running" class="btn btn-ai">
                <Spinner v-if="running" class="size-4" />
                <Icon v-else name="bolt" class="size-4" />
                {{ running ? 'Running…' : 'Run' }}
            </button>
        </div>

        <div v-if="error" class="mx-5 mb-4 flex gap-3 rounded-xl bg-red-50 p-3.5 text-sm text-red-700 ring-1 ring-red-600/10 sm:mx-6">
            <Icon name="warning" class="size-5 shrink-0" />
            <span>{{ error }}</span>
        </div>

        <AgentResult v-if="(running || log) && !error" class="border-t border-zinc-950/5 px-5 py-5 sm:px-6"
                     :log="log" :running="running" :sheet-url="sheetUrl" />
    </article>
</template>
