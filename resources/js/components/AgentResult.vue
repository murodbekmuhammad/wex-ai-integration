<script setup>
import Icon from './Icon.vue';

defineProps({ log: String, running: Boolean, sheetUrl: String });
</script>

<template>
    <div class="min-w-0">
        <div v-if="running && !log" class="flex items-center gap-1.5 py-2" aria-label="The agent is planning">
            <span class="size-2 animate-bounce rounded-full bg-violet-400"></span>
            <span class="size-2 animate-bounce rounded-full bg-violet-400 [animation-delay:150ms]"></span>
            <span class="size-2 animate-bounce rounded-full bg-violet-400 [animation-delay:300ms]"></span>
        </div>
        <p class="text-sm leading-relaxed break-words whitespace-pre-wrap text-zinc-700">{{ log }}</p>

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
</template>
