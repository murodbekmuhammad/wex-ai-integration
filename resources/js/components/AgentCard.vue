<script setup>
import { useAgentRun } from '../useAgentRun';
import AgentTimeline from './AgentTimeline.vue';
import Icon from './Icon.vue';
import Spinner from './Spinner.vue';

const props = defineProps({ agent: Object });
const emit = defineEmits(['unauthorized', 'ran']);

const { steps, planning, summary, notice, sheetUrl, running, error, elapsed, now, hasActivity, start } = useAgentRun(() => emit('unauthorized'));

async function run() {
    await start(`/agents/${props.agent.key}/run`);
    emit('ran');
}
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

        <div class="mt-auto flex items-center gap-3 border-t border-zinc-950/5 px-5 py-3 sm:px-6">
            <p class="mr-auto text-xs text-zinc-500">
                <template v-if="running">Working… this usually takes about a minute.</template>
                <template v-else-if="hasActivity">Run again to refresh the report.</template>
                <template v-else>The Google Sheet link appears here when it’s ready.</template>
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

        <AgentTimeline v-if="hasActivity && !error" class="border-t border-zinc-950/5 bg-zinc-50/40 px-5 py-5 sm:px-6"
                       :steps="steps" :planning="planning" :summary="summary" :notice="notice" :sheet-url="sheetUrl"
                       :running="running" :elapsed="elapsed" :now="now" />
    </article>
</template>
