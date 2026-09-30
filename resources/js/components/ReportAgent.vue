<script setup>
import { computed, ref } from 'vue';
import { apiStream } from '../api';
import Icon from './Icon.vue';
import Spinner from './Spinner.vue';

const emit = defineEmits(['done', 'unauthorized']);

const task = ref('');
const asked = ref('');
const log = ref('');
const running = ref(false);
const error = ref('');

// What the "Generate aging report" button asks the agent to do.
const AGING_REPORT_TASK = 'Collect new PDFs from Gmail and create the aging report Google Sheet from the newest invoice aging report.';

// The newest Google Sheet link from the app's own progress lines (indented), never from Claude's summary text.
const sheetUrl = computed(() => {
    const links = [...log.value.matchAll(/^ {2}.*?(https:\/\/docs\.google\.com\/\S+)/gm)];
    return links.length ? links[links.length - 1][1] : null;
});

async function run(preset) {
    const text = typeof preset === 'string' ? preset : task.value.trim();
    if (!text || running.value) return;

    running.value = true;
    asked.value = text;
    log.value = '';
    error.value = '';

    try {
        await apiStream('/agent', { task: asked.value }, (text) => {
            log.value += text;
        });
        if (text === task.value.trim()) task.value = '';
        emit('done');
    } catch (e) {
        if (e.status === 401) return emit('unauthorized');
        error.value = Object.values(e.errors).flat()[0] || e.message;
    } finally {
        running.value = false;
    }
}
</script>

<template>
    <section class="animate-fade-up rounded-2xl bg-linear-to-br from-violet-500/70 via-indigo-500/50 to-sky-400/60 p-px shadow-xl shadow-violet-500/10">
        <div class="rounded-[15px] bg-white">
            <form @submit.prevent="run">
                <div class="flex items-center gap-2 px-5 pt-4 text-sm font-medium sm:px-6">
                    <Icon name="bolt" class="size-4 text-violet-600" />
                    <span class="bg-linear-to-r from-violet-700 to-indigo-600 bg-clip-text text-transparent">Report agent</span>
                </div>
                <textarea v-model="task" rows="3" maxlength="2000"
                          @keydown.enter.exact.prevent="run"
                          placeholder="Give the agent a task, e.g. “Build a table of this month’s invoice PDFs with totals, put it in Google Sheets and email me the link”"
                          class="block w-full resize-none border-0 bg-transparent px-5 py-3 text-base text-zinc-900 placeholder:text-zinc-400 focus:ring-0 focus:outline-none sm:px-6"></textarea>
                <div class="flex flex-wrap items-center gap-3 border-t border-zinc-950/5 px-5 py-3 sm:px-6">
                    <p class="mr-auto text-xs text-zinc-500">
                        The Google Sheet link appears here when the report is ready.
                    </p>
                    <button type="button" @click="run(AGING_REPORT_TASK)" :disabled="running" class="btn btn-secondary">
                        <Icon name="table" class="size-4" />
                        Generate aging report
                    </button>
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
                    <div class="min-w-0 flex-1 pt-1">
                        <div v-if="running && !log" class="flex items-center gap-1.5 py-2" aria-label="The agent is planning">
                            <span class="size-2 animate-bounce rounded-full bg-violet-400"></span>
                            <span class="size-2 animate-bounce rounded-full bg-violet-400 [animation-delay:150ms]"></span>
                            <span class="size-2 animate-bounce rounded-full bg-violet-400 [animation-delay:300ms]"></span>
                        </div>
                        <p class="leading-relaxed break-words whitespace-pre-wrap text-zinc-700">{{ log }}</p>

                        <div v-if="sheetUrl" class="mt-4 flex flex-wrap items-center gap-3 rounded-xl bg-emerald-50 p-4 ring-1 ring-emerald-600/15">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-emerald-600 text-white">
                                <Icon name="table" class="size-5" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-emerald-900">{{ running ? 'Google Sheet created' : 'Report ready' }}</p>
                                <p class="truncate text-xs text-emerald-800/80">{{ sheetUrl }}</p>
                            </div>
                            <a :href="sheetUrl" target="_blank" rel="noopener" class="btn btn-secondary">
                                <Icon name="external" class="size-4" />
                                Open in Google Sheets
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
