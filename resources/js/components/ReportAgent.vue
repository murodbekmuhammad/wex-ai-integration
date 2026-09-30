<script setup>
import { ref } from 'vue';
import { useAgentRun } from '../useAgentRun';
import AgentResult from './AgentResult.vue';
import Icon from './Icon.vue';
import Spinner from './Spinner.vue';

const emit = defineEmits(['done', 'unauthorized']);

const task = ref('');
const asked = ref('');
const { log, running, error, sheetUrl, start } = useAgentRun(() => emit('unauthorized'));

async function run() {
    const text = task.value.trim();
    if (!text || running.value) return;

    asked.value = text;

    if (await start('/agent', { task: text })) {
        if (task.value.trim() === text) task.value = '';
        emit('done');
    }
}
</script>

<template>
    <section class="animate-fade-up rounded-2xl bg-linear-to-br from-violet-500/70 via-indigo-500/50 to-sky-400/60 p-px shadow-xl shadow-violet-500/10">
        <div class="rounded-[15px] bg-white">
            <form @submit.prevent="run">
                <div class="flex items-center gap-2 px-5 pt-4 text-sm font-medium sm:px-6">
                    <Icon name="bolt" class="size-4 text-violet-600" />
                    <span class="bg-linear-to-r from-violet-700 to-indigo-600 bg-clip-text text-transparent">Custom agent task</span>
                </div>
                <textarea id="agent-task" v-model="task" rows="3" maxlength="2000"
                          @keydown.enter.exact.prevent="run"
                          placeholder="Give the agent a task, e.g. “Build a table of this month’s payment reports with totals and put it in Google Sheets”"
                          class="block w-full resize-none border-0 bg-transparent px-5 py-3 text-base text-zinc-900 placeholder:text-zinc-400 focus:ring-0 focus:outline-none sm:px-6"></textarea>
                <div class="flex flex-wrap items-center gap-3 border-t border-zinc-950/5 px-5 py-3 sm:px-6">
                    <p class="mr-auto text-xs text-zinc-500">
                        The agent can use every tool. The Google Sheet link appears here when it’s ready.
                    </p>
                    <button :disabled="running || !task.trim()" class="btn btn-ai">
                        <Spinner v-if="running" class="size-4" />
                        <Icon v-else name="bolt" class="size-4" />
                        {{ running ? 'Working…' : 'Run agent' }}
                    </button>
                </div>
            </form>

            <div v-if="error" class="mx-5 mb-4 flex gap-3 rounded-xl bg-red-50 p-3.5 text-sm text-red-700 ring-1 ring-red-600/10 sm:mx-6">
                <Icon name="warning" class="size-5 shrink-0" />
                <span>{{ error }}</span>
            </div>

            <div v-if="asked && !error" class="space-y-4 border-t border-zinc-950/5 px-5 py-5 text-sm sm:px-6">
                <div class="flex justify-end">
                    <p class="max-w-2xl rounded-2xl rounded-br-md bg-zinc-900 px-4 py-2.5 text-white">{{ asked }}</p>
                </div>
                <div class="flex gap-3">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-violet-500 to-indigo-600 text-white shadow-md shadow-violet-500/30">
                        <Icon name="bolt" class="size-4" />
                    </span>
                    <AgentResult class="flex-1 pt-1" :log="log" :running="running" :sheet-url="sheetUrl" />
                </div>
            </div>
        </div>
    </section>
</template>
