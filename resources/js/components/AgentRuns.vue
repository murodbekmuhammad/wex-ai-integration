<script setup>
import Icon from './Icon.vue';
import Spinner from './Spinner.vue';

defineProps({ runs: Array, loading: Boolean, error: String });

const statuses = {
    completed: { label: 'Completed', icon: 'check', class: 'bg-emerald-50 text-emerald-700 ring-emerald-600/15' },
    stopped: { label: 'Stopped', icon: 'warning', class: 'bg-amber-50 text-amber-800 ring-amber-600/20' },
    failed: { label: 'Failed', icon: 'x', class: 'bg-red-50 text-red-700 ring-red-600/15' },
};

const when = (value) => new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
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

        <ul v-else class="divide-y divide-zinc-950/5">
            <li v-for="run in runs" :key="run.id" class="space-y-2 px-5 py-4 sm:px-6">
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium ring-1" :class="statuses[run.status]?.class">
                        <Icon :name="statuses[run.status]?.icon || 'bolt'" class="size-3" />
                        {{ statuses[run.status]?.label || run.status }}
                    </span>
                    <span class="text-zinc-500">{{ run.sheet_mode === 'new' ? 'New Google Sheet' : 'Existing Google Sheet' }}</span>
                    <span class="ml-auto text-zinc-400 tabular-nums">{{ when(run.created_at) }}</span>
                </div>

                <p v-if="run.summary" class="text-sm whitespace-pre-line text-zinc-700">{{ run.summary }}</p>

                <ul v-if="run.steps.length" class="space-y-0.5 text-xs text-zinc-500">
                    <li v-for="(step, index) in run.steps" :key="index" class="flex gap-1.5">
                        <Icon :name="step.ok ? 'check' : 'x'" class="mt-0.5 size-3 shrink-0" :class="step.ok ? 'text-emerald-600' : 'text-red-500'" />
                        <span>{{ step.text || step.label }}</span>
                    </li>
                </ul>

                <a v-if="run.google_sheet_url" :href="run.google_sheet_url" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-1 text-sm font-medium text-violet-700 hover:text-violet-900">
                    Open Google Sheet <Icon name="external" class="size-3.5" />
                </a>
            </li>
        </ul>
    </section>
</template>
