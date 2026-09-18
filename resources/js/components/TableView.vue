<script setup>
import { ref, watch } from 'vue';
import { api } from '../api';
import Icon from './Icon.vue';
import Spinner from './Spinner.vue';

const props = defineProps({ table: Object, busy: Boolean });
const emit = defineEmits(['revise', 'close', 'unauthorized']);

const change = ref('');
const sheetUrl = ref(props.table.google_sheet_url);
const uploading = ref(false);
const sheetError = ref('');
const needsDriveAccess = ref(false);

async function uploadToGoogleSheets() {
    uploading.value = true;
    sheetError.value = '';
    needsDriveAccess.value = false;

    // Opened before the request so browsers don't treat it as an unrequested popup.
    const tab = window.open('', '_blank');

    try {
        sheetUrl.value = (await api('POST', `/tables/${props.table.id}/google-sheet`)).google_sheet_url;
        if (tab) tab.location = sheetUrl.value;
    } catch (e) {
        tab?.close();
        if (e.status === 401) return emit('unauthorized');
        needsDriveAccess.value = e.status === 403;
        sheetError.value = e.message;
    } finally {
        uploading.value = false;
    }
}

function revise() {
    if (!change.value.trim() || props.busy) return;
    emit('revise', change.value.trim());
}

// The request box empties once the revised table arrives, and keeps its text if revising fails.
watch(() => props.table.id, () => {
    change.value = '';
    sheetUrl.value = props.table.google_sheet_url;
    sheetError.value = '';
    needsDriveAccess.value = false;
});

const isNumber = (value) => typeof value === 'number';
const formatCell = (value) => (isNumber(value) ? value.toLocaleString(undefined, { maximumFractionDigits: 2 }) : value ?? '');
</script>

<template>
    <section class="card animate-fade-up overflow-hidden">
        <div class="flex flex-wrap items-start gap-4 border-b border-zinc-950/5 px-5 py-4 sm:px-6">
            <span class="icon-badge bg-emerald-50 text-emerald-600 ring-emerald-600/10">
                <Icon name="table" class="size-5" />
            </span>
            <div class="min-w-0 flex-1">
                <h3 class="font-semibold text-zinc-950">{{ table.title }}</h3>
                <p class="truncate text-sm text-zinc-500" :title="table.request">{{ table.request }}</p>
            </div>
            <button @click="emit('close')" title="Close table" class="btn btn-ghost -mr-2 px-2">
                <Icon name="x" class="size-5" />
                <span class="sr-only">Close table</span>
            </button>

            <div class="flex w-full flex-wrap gap-2">
                <a :href="`/tables/${table.id}/download/xlsx`" class="btn btn-secondary">
                    <span class="flex size-5 items-center justify-center rounded bg-emerald-600 text-[9px] font-bold text-white">X</span>
                    Excel
                </a>
                <a :href="`/tables/${table.id}/download/pdf`" class="btn btn-secondary">
                    <span class="flex size-5 items-center justify-center rounded bg-rose-500 text-[8px] font-bold text-white">PDF</span>
                    PDF
                </a>
                <button @click="uploadToGoogleSheets" :disabled="uploading" class="btn btn-secondary">
                    <Spinner v-if="uploading" class="size-5 text-emerald-600" />
                    <svg v-else viewBox="0 0 20 20" class="size-5" aria-hidden="true">
                        <path fill="#0F9D58" d="M12.5 0H3.75A1.75 1.75 0 0 0 2 1.75v16.5C2 19.216 2.784 20 3.75 20h12.5A1.75 1.75 0 0 0 18 18.25V5.5L12.5 0Z"/>
                        <path fill="#87CEAC" d="M12.5 0v4.25c0 .69.56 1.25 1.25 1.25H18L12.5 0Z"/>
                        <path fill="#fff" d="M5.5 9h9v7.5h-9V9Zm1.25 1.25v1.5h3v-1.5h-3Zm4.25 0v1.5h2.25v-1.5H11Zm-4.25 2.75v1.5h3V13h-3Zm4.25 0v1.5h2.25V13H11Z"/>
                    </svg>
                    {{ uploading ? 'Uploading…' : sheetUrl ? 'Open in Google Sheets' : 'Upload to Google Sheets' }}
                    <Icon v-if="sheetUrl && !uploading" name="external" class="size-3.5 text-zinc-400" />
                </button>
            </div>
        </div>

        <div class="space-y-4 px-5 py-5 text-sm sm:px-6">
            <div v-if="sheetError" class="flex gap-3 rounded-xl bg-red-50 p-3.5 text-red-700 ring-1 ring-red-600/10">
                <Icon name="warning" class="size-5 shrink-0" />
                <span>
                    {{ sheetError }}
                    <a v-if="needsDriveAccess" href="/auth/google" class="font-semibold underline underline-offset-2">Sign in again</a>
                </span>
            </div>

            <p v-if="table.summary" class="rounded-xl bg-zinc-50 p-4 leading-relaxed whitespace-pre-line text-zinc-700 ring-1 ring-zinc-950/5">{{ table.summary }}</p>

            <div v-for="warning in table.warnings" :key="warning"
                 class="flex gap-3 rounded-xl bg-amber-50 p-3.5 text-amber-800 ring-1 ring-amber-600/15">
                <Icon name="warning" class="size-5 shrink-0" />
                <span>{{ warning }}</span>
            </div>

            <div class="scroll-thin max-h-[28rem] overflow-auto rounded-xl ring-1 ring-zinc-950/10">
                <table class="w-full text-left">
                    <thead class="sticky top-0 z-10">
                        <tr class="bg-zinc-50/95 text-xs font-medium tracking-wide text-zinc-500 uppercase backdrop-blur">
                            <th v-for="(column, i) in table.columns" :key="i"
                                class="border-b border-zinc-950/10 px-4 py-3 font-medium whitespace-nowrap">{{ column }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-950/5">
                        <tr v-for="(row, r) in table.rows" :key="r" class="transition-colors even:bg-zinc-50/50 hover:bg-violet-50/50">
                            <td v-for="(cell, i) in row" :key="i" class="px-4 py-2.5"
                                :class="isNumber(cell) ? 'text-right font-medium whitespace-nowrap text-zinc-900 tabular-nums' : 'text-zinc-700'">
                                {{ formatCell(cell) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <form @submit.prevent="revise" class="flex flex-col gap-2 sm:flex-row">
                <div class="relative flex-1">
                    <Icon name="sparkles" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-violet-500" />
                    <input v-model="change" maxlength="2000"
                           placeholder="Change this table, e.g. “add a total row” or “only show Tashkent”"
                           class="field w-full py-2.5 pl-10">
                </div>
                <button :disabled="busy || !change.trim()" class="btn btn-ai">
                    <Spinner v-if="busy" class="size-4" />
                    <Icon v-else name="send" class="size-4" />
                    {{ busy ? 'Updating…' : 'Update table' }}
                </button>
            </form>
            <p class="text-xs text-zinc-500">Each update is saved as a new table, so earlier versions stay downloadable.</p>
        </div>
    </section>
</template>
