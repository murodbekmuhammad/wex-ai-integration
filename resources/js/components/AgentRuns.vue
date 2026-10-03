<script setup>
import { ref } from 'vue';
import Icon from './Icon.vue';
import Spinner from './Spinner.vue';

defineProps({ runs: Array, loading: Boolean, error: String });

const statuses = {
    completed: { label: 'Completed', icon: 'check', class: 'bg-emerald-50 text-emerald-700 ring-emerald-600/15' },
    stopped: { label: 'Stopped', icon: 'warning', class: 'bg-amber-50 text-amber-800 ring-amber-600/20' },
    failed: { label: 'Failed', icon: 'x', class: 'bg-red-50 text-red-700 ring-red-600/15' },
};

const when = (value) => new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
const stepsDone = (run) => run.steps.filter((step) => step.ok).length;

// Ids of the runs whose full result is showing.
const expanded = ref(new Set());

function toggle(id) {
    if (expanded.value.has(id)) expanded.value.delete(id);
    else expanded.value.add(id);
}
</script>

<template>
    <section class="card overflow-hidden">
        <h3 class="border-b border-zinc-950/5 px-5 py-3 text-sm font-semibold text-zinc-950 sm:px-6">Results</h3>

        <div v-if="loading" class="flex items-center gap-2 px-5 py-4 text-sm text-zinc-500 sm:px-6">
            <Spinner class="size-4 text-violet-600" /> Loading results…
        </div>

        <p v-else-if="error" class="flex gap-2 px-5 py-4 text-sm text-red-700 sm:px-6">
            <Icon name="warning" class="size-5 shrink-0" /> {{ error }}
        </p>

        <p v-else-if="!runs.length" class="px-5 py-4 text-sm text-zinc-500 sm:px-6">No results yet. Each run’s result is saved here.</p>

        <div v-else class="scroll-thin overflow-x-auto">
            <table class="w-full table-fixed text-left text-sm">
                <thead>
                    <tr class="bg-zinc-50/95 text-xs font-medium tracking-wide text-zinc-500 uppercase">
                        <th class="w-10 border-b border-zinc-950/10 py-3 pl-3"><span class="sr-only">Expand</span></th>
                        <th class="w-40 border-b border-zinc-950/10 px-3 py-3 font-medium whitespace-nowrap">Date</th>
                        <th class="w-32 border-b border-zinc-950/10 px-3 py-3 font-medium whitespace-nowrap">Status</th>
                        <th class="w-60 border-b border-zinc-950/10 px-3 py-3 font-medium whitespace-nowrap">Summary</th>
                        <th class="w-20 border-b border-zinc-950/10 px-3 py-3 font-medium whitespace-nowrap">Steps</th>
                        <th class="w-28 border-b border-zinc-950/10 px-3 py-3 pr-5 font-medium whitespace-nowrap sm:pr-6">Sheet</th>
                    </tr>
                </thead>
                <tbody v-for="run in runs" :key="run.id" class="border-b border-zinc-950/5 last:border-b-0">
                    <tr class="cursor-pointer transition-colors hover:bg-violet-50/50" :class="{ 'bg-violet-50/40': expanded.has(run.id) }"
                        @click="toggle(run.id)">
                        <td class="py-2.5 pl-3">
                            <button type="button" class="btn btn-ghost p-1" :aria-expanded="expanded.has(run.id)"
                                    :title="expanded.has(run.id) ? 'Hide full result' : 'View full result'" @click.stop="toggle(run.id)">
                                <Icon name="chevron-right" class="size-4 transition-transform" :class="{ 'rotate-90': expanded.has(run.id) }" />
                                <span class="sr-only">{{ expanded.has(run.id) ? 'Hide full result' : 'View full result' }}</span>
                            </button>
                        </td>
                        <td class="px-3 py-2.5 whitespace-nowrap text-zinc-500 tabular-nums">{{ when(run.created_at) }}</td>
                        <td class="px-3 py-2.5">
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1" :class="statuses[run.status]?.class">
                                <Icon :name="statuses[run.status]?.icon || 'bolt'" class="size-3" />
                                {{ statuses[run.status]?.label || run.status }}
                            </span>
                        </td>
                        <td class="truncate px-3 py-2.5 text-zinc-700">{{ run.summary || '—' }}</td>
                        <td class="px-3 py-2.5 whitespace-nowrap text-zinc-500 tabular-nums">{{ stepsDone(run) }} / {{ run.steps.length }}</td>
                        <td class="px-3 py-2.5 pr-5 whitespace-nowrap sm:pr-6">
                            <a v-if="run.google_sheet_url" :href="run.google_sheet_url" target="_blank" rel="noopener" @click.stop
                               class="inline-flex items-center gap-1 font-medium text-violet-700 hover:text-violet-900">
                                Open <Icon name="external" class="size-3.5" />
                            </a>
                            <span v-else class="text-zinc-400">—</span>
                        </td>
                    </tr>

                    <tr v-if="expanded.has(run.id)" class="bg-zinc-50/60">
                        <td colspan="6" class="space-y-3 px-5 py-4 sm:px-6">
                            <p class="text-xs text-zinc-500">{{ run.sheet_mode === 'new' ? 'New Google Sheet' : 'Existing Google Sheet' }}</p>

                            <div v-if="run.summary">
                                <p class="mb-1 text-xs font-medium tracking-wide text-zinc-400 uppercase">Summary</p>
                                <p class="break-words whitespace-pre-line text-zinc-700">{{ run.summary }}</p>
                            </div>

                            <div v-if="run.steps.length">
                                <p class="mb-1 text-xs font-medium tracking-wide text-zinc-400 uppercase">Steps</p>
                                <ul class="space-y-0.5 text-xs text-zinc-500">
                                    <li v-for="(step, index) in run.steps" :key="index" class="flex gap-1.5">
                                        <Icon :name="step.ok ? 'check' : 'x'" class="mt-0.5 size-3 shrink-0" :class="step.ok ? 'text-emerald-600' : 'text-red-500'" />
                                        <span class="break-words">{{ step.text || step.label }}</span>
                                    </li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
