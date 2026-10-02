<script setup>
import { computed, onMounted, ref } from 'vue';
import { api } from '../api';
import AppHeader from './AppHeader.vue';
import Icon from './Icon.vue';
import Spinner from './Spinner.vue';

defineProps({ user: Object });
const emit = defineEmits(['logout']);

// Where a run puts its report: the agent's existing Google Sheet, or a new one.
const sheetMode = ref('existing');
const sheetOptions = [
    { value: 'existing', label: 'Existing Google Sheet', hint: 'Every run updates the same sheet and keeps your team’s notes.' },
    { value: 'new', label: 'New Google Sheet', hint: 'Every run puts its report in a new sheet.' },
];

// The invoice aging PDFs collected from email; with "all" on, the agent uses every one of them.
const pdfs = ref([]);
const all = ref(true);
const selected = ref([]);

const loading = ref(true);
const saving = ref(false);
const saved = ref(false);
const error = ref('');

const canSave = computed(() => !saving.value && (all.value || selected.value.length > 0));

function handleError(e) {
    if (e.status === 401) return emit('logout');
    error.value = Object.values(e.errors || {}).flat()[0] || e.message;
}

function apply(settings) {
    const ids = new Set(pdfs.value.map((pdf) => pdf.id));

    sheetMode.value = settings.sheet_mode;
    all.value = settings.pdf_document_ids === null;
    selected.value = (settings.pdf_document_ids ?? []).filter((id) => ids.has(id));
}

async function save() {
    saving.value = true;
    saved.value = false;
    error.value = '';

    try {
        const data = await api('PUT', '/agent-settings', {
            sheet_mode: sheetMode.value,
            pdf_document_ids: all.value ? null : selected.value,
        });
        apply(data.settings);
        saved.value = true;
    } catch (e) {
        handleError(e);
    } finally {
        saving.value = false;
    }
}

const formatDate = (value) => new Date(value).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });

onMounted(async () => {
    try {
        const data = await api('GET', '/agent-settings');
        pdfs.value = data.pdfs;
        apply(data.settings);
    } catch (e) {
        handleError(e);
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

        <AppHeader :user="user" page="settings" @logout="emit('logout')" />

        <main class="mx-auto max-w-3xl space-y-8 px-4 py-10 sm:px-6">
            <div class="animate-fade-up">
                <h1 class="text-3xl font-semibold tracking-tight text-zinc-950 sm:text-4xl">Agent settings</h1>
                <p class="mt-2 text-zinc-500">Choose where the agent puts its report and which invoice aging PDFs it works with.</p>
            </div>

            <div v-if="loading" class="flex items-center gap-2 text-sm text-zinc-500">
                <Spinner class="size-4 text-violet-600" /> Loading settings…
            </div>

            <form v-else @submit.prevent="save" class="animate-fade-up space-y-6">
                <section class="card p-5 sm:p-6">
                    <div class="flex items-start gap-4">
                        <span class="icon-badge bg-violet-50 text-violet-600 ring-violet-600/10">
                            <Icon name="table" class="size-5" />
                        </span>
                        <div>
                            <h2 class="font-semibold text-zinc-950">Google Sheet</h2>
                            <p class="mt-1 text-sm text-zinc-500">Where each run puts its report.</p>
                        </div>
                    </div>

                    <div role="radiogroup" aria-label="Google Sheet to use" class="mt-4 grid gap-3 sm:grid-cols-2">
                        <button v-for="option in sheetOptions" :key="option.value" type="button" role="radio"
                                :aria-checked="sheetMode === option.value" @click="sheetMode = option.value; saved = false"
                                class="rounded-xl p-4 text-left ring-1 transition"
                                :class="sheetMode === option.value ? 'bg-violet-50/60 ring-violet-600/40' : 'bg-white ring-zinc-950/10 hover:ring-zinc-950/20'">
                            <span class="block text-sm font-medium text-zinc-950">{{ option.label }}</span>
                            <span class="mt-1 block text-sm text-zinc-500">{{ option.hint }}</span>
                        </button>
                    </div>
                </section>

                <section class="card overflow-hidden">
                    <div class="flex items-start gap-4 p-5 sm:p-6">
                        <span class="icon-badge bg-rose-50 text-rose-600 ring-rose-600/10">
                            <Icon name="document" class="size-5" />
                        </span>
                        <div>
                            <h2 class="font-semibold text-zinc-950">Invoice aging PDFs</h2>
                            <p class="mt-1 text-sm text-zinc-500">The PDFs from your email the agent may use. It builds the report from the newest of them.</p>
                        </div>
                    </div>

                    <label class="flex cursor-pointer items-center gap-3 border-t border-zinc-950/5 px-5 py-3 text-sm sm:px-6">
                        <input type="checkbox" v-model="all" @change="saved = false" class="size-4 cursor-pointer rounded accent-violet-600">
                        <span class="font-medium text-zinc-900">All</span>
                        <span class="text-zinc-500">every invoice aging PDF, including ones that arrive later</span>
                    </label>

                    <p v-if="!pdfs.length" class="border-t border-zinc-950/5 px-5 py-6 text-sm text-zinc-500 sm:px-6">
                        No invoice aging PDFs have been collected yet. Run the agent or collect PDFs in the workspace first.
                    </p>

                    <ul v-else class="scroll-thin max-h-96 divide-y divide-zinc-950/5 overflow-y-auto border-t border-zinc-950/5"
                        :class="{ 'opacity-50': all }">
                        <li v-for="pdf in pdfs" :key="pdf.id">
                            <label class="flex items-center gap-3 px-5 py-2.5 text-sm sm:px-6" :class="all ? 'cursor-not-allowed' : 'cursor-pointer hover:bg-zinc-50'">
                                <input type="checkbox" v-model="selected" :value="pdf.id" :disabled="all" @change="saved = false"
                                       class="size-4 rounded accent-violet-600" :class="all ? 'cursor-not-allowed' : 'cursor-pointer'">
                                <span class="flex h-7 w-6 shrink-0 items-end justify-center rounded-[5px] bg-rose-500 pb-0.5 text-[8px] font-bold text-white shadow-sm shadow-rose-500/30">PDF</span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium text-zinc-900" :title="pdf.filename">{{ pdf.filename }}</span>
                                    <span class="block truncate text-xs text-zinc-500" :title="pdf.subject">{{ pdf.sender_email || pdf.sender }} · {{ pdf.subject || '(no subject)' }}</span>
                                </span>
                                <span class="whitespace-nowrap text-xs text-zinc-500 tabular-nums">{{ formatDate(pdf.sent_at) }}</span>
                            </label>
                        </li>
                    </ul>

                    <p v-if="!all && pdfs.length" class="border-t border-zinc-950/5 px-5 py-3 text-xs text-zinc-500 sm:px-6">
                        {{ selected.length ? `${selected.length} of ${pdfs.length} selected` : 'Pick at least one PDF, or tick All.' }}
                    </p>
                </section>

                <div v-if="error" class="flex gap-3 rounded-xl bg-red-50 p-3.5 text-sm text-red-700 ring-1 ring-red-600/10">
                    <Icon name="warning" class="size-5 shrink-0" />
                    <span>{{ error }}</span>
                </div>

                <div class="flex items-center justify-end gap-3">
                    <span v-if="saved" class="inline-flex items-center gap-1.5 text-sm text-emerald-700">
                        <Icon name="check" class="size-4" /> Saved
                    </span>
                    <button type="submit" :disabled="!canSave" class="btn btn-primary">
                        <Spinner v-if="saving" class="size-4" />
                        {{ saving ? 'Saving…' : 'Save' }}
                    </button>
                </div>
            </form>
        </main>
    </div>
</template>
