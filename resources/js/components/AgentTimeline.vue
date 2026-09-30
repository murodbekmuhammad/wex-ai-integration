<script setup>
import { computed } from 'vue';
import Icon from './Icon.vue';
import Spinner from './Spinner.vue';

const props = defineProps({
    steps: Array,
    planning: Boolean,
    summary: String,
    notice: Object,
    sheetUrl: String,
    running: Boolean,
    elapsed: Number,
    now: Number,
});

// Which icon stands for each tool.
const toolIcons = {
    collect_pdfs: 'inbox',
    find_pdfs: 'document',
    create_aging_report: 'table',
    build_table: 'sparkles',
    upload_to_google_sheets: 'cloud',
    email_sheet_link: 'send',
};

// 7 → "7s", 75 → "1:15"
function duration(ms) {
    const seconds = Math.max(0, Math.round(ms / 1000));
    return seconds < 60 ? `${seconds}s` : `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

const stepTime = (step) => duration((step.finishedAt ?? props.now) - step.startedAt);

// The running label ends in "…"; a finished step reads as a plain title.
const title = (step) => (step.status === 'running' ? step.label : step.label.replace(/…$/, ''));

const status = computed(() => {
    if (props.running) return `Running · ${duration(props.elapsed)}`;
    if (props.notice?.kind === 'error') return `Failed after ${duration(props.elapsed)}`;
    if (props.notice?.kind === 'stopped') return `Stopped after ${duration(props.elapsed)}`;
    return `Finished in ${duration(props.elapsed)}`;
});

const doneCount = computed(() => props.steps.filter((step) => step.status === 'done').length);
</script>

<template>
    <div class="min-w-0 space-y-5">
        <div class="flex items-center justify-between gap-3 text-xs font-medium">
            <span class="inline-flex items-center gap-2 rounded-full px-2.5 py-1 ring-1"
                  :class="running ? 'bg-violet-50 text-violet-700 ring-violet-600/15'
                      : notice ? 'bg-amber-50 text-amber-800 ring-amber-600/20'
                      : 'bg-emerald-50 text-emerald-700 ring-emerald-600/15'">
                <span v-if="running" class="relative flex size-2">
                    <span class="absolute inline-flex size-full animate-ping rounded-full bg-violet-400 opacity-60"></span>
                    <span class="relative inline-flex size-2 rounded-full bg-violet-500"></span>
                </span>
                <Icon v-else :name="notice ? 'warning' : 'check'" class="size-3.5" />
                <span class="tabular-nums">{{ status }}</span>
            </span>
            <span class="text-zinc-400 tabular-nums">{{ doneCount }} {{ doneCount === 1 ? 'step' : 'steps' }} done</span>
        </div>

        <ol class="relative">
            <li v-for="(step, index) in steps" :key="index" class="relative flex gap-4 pb-5">
                <span v-if="index < steps.length - 1 || planning" class="absolute top-10 bottom-0 left-[1.1875rem] w-px bg-zinc-200" aria-hidden="true"></span>

                <span class="relative flex size-10 shrink-0 items-center justify-center rounded-xl ring-1 transition-colors"
                      :class="{
                          'bg-violet-50 text-violet-600 ring-violet-600/20': step.status === 'running',
                          'bg-white text-zinc-700 ring-zinc-950/10': step.status === 'done',
                          'bg-red-50 text-red-600 ring-red-600/20': step.status === 'failed',
                      }">
                    <Spinner v-if="step.status === 'running'" class="size-5" />
                    <Icon v-else :name="toolIcons[step.tool] || 'bolt'" class="size-5" />
                    <span v-if="step.status !== 'running'"
                          class="absolute -right-1 -bottom-1 flex size-4 items-center justify-center rounded-full text-white ring-2 ring-white"
                          :class="step.status === 'done' ? 'bg-emerald-500' : 'bg-red-500'">
                        <Icon :name="step.status === 'done' ? 'check' : 'x'" class="size-2.5" />
                    </span>
                </span>

                <div class="min-w-0 flex-1 pt-0.5">
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="font-medium text-zinc-900">{{ title(step) }}</p>
                        <span class="shrink-0 text-xs text-zinc-400 tabular-nums">{{ stepTime(step) }}</span>
                    </div>
                    <p v-if="step.text" class="mt-0.5 text-sm break-words"
                       :class="step.status === 'failed' ? 'text-red-700' : 'text-zinc-500'">{{ step.text }}</p>
                    <p v-else class="mt-0.5 text-sm text-zinc-400">Working on it…</p>
                </div>
            </li>

            <li v-if="planning" class="relative flex gap-4 pb-2" aria-live="polite">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl border border-dashed border-violet-300 bg-violet-50/60 text-violet-600">
                    <Icon name="sparkles" class="size-5 animate-pulse" />
                </span>
                <div class="pt-0.5">
                    <p class="font-medium text-zinc-900">Planning</p>
                    <p class="mt-0.5 flex items-center gap-2 text-sm text-zinc-500">
                        {{ steps.length ? 'Claude is checking the result and choosing the next step' : 'Claude is reading the task' }}
                        <span class="flex gap-1" aria-hidden="true">
                            <span class="size-1.5 animate-bounce rounded-full bg-violet-400"></span>
                            <span class="size-1.5 animate-bounce rounded-full bg-violet-400 [animation-delay:150ms]"></span>
                            <span class="size-1.5 animate-bounce rounded-full bg-violet-400 [animation-delay:300ms]"></span>
                        </span>
                    </p>
                </div>
            </li>
        </ol>

        <div v-if="sheetUrl" class="flex flex-wrap items-center gap-3 rounded-xl bg-emerald-50 p-4 ring-1 ring-emerald-600/15">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-emerald-600 text-white shadow-sm shadow-emerald-600/30">
                <Icon name="table" class="size-5" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="font-medium text-emerald-950">{{ running ? 'Google Sheet created' : 'Report ready' }}</p>
                <p class="truncate text-xs text-emerald-800/80">{{ sheetUrl }}</p>
            </div>
            <a :href="sheetUrl" target="_blank" rel="noopener" class="btn btn-secondary">
                <Icon name="external" class="size-4" />
                Open in Google Sheets
            </a>
        </div>

        <div v-if="notice" class="flex gap-3 rounded-xl p-3.5 text-sm ring-1"
             :class="notice.kind === 'error' ? 'bg-red-50 text-red-700 ring-red-600/10' : 'bg-amber-50 text-amber-800 ring-amber-600/15'">
            <Icon name="warning" class="size-5 shrink-0" />
            <span>{{ notice.text }}</span>
        </div>

        <div v-if="summary" class="flex gap-3">
            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-violet-500 to-indigo-600 text-white shadow-md shadow-violet-500/30">
                <Icon name="sparkles" class="size-4" />
            </span>
            <div class="min-w-0 flex-1 rounded-2xl rounded-tl-md bg-zinc-50 px-4 py-3 ring-1 ring-zinc-950/5">
                <p class="mb-1 text-xs font-medium tracking-wide text-zinc-400 uppercase">Summary</p>
                <p class="text-sm leading-relaxed break-words whitespace-pre-wrap text-zinc-700">{{ summary }}</p>
            </div>
        </div>
    </div>
</template>
