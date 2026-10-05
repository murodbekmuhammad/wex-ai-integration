<script setup>
import { computed, onMounted, ref } from 'vue';
import { api } from '../api';
import AppHeader from './AppHeader.vue';
import Icon from './Icon.vue';
import Spinner from './Spinner.vue';

defineProps({ user: Object });
const emit = defineEmits(['logout']);

// The agents to switch between; each one's settings are loaded and saved on their own.
const agents = ref([]);
const agentKey = ref('');
const reportName = (agent) => (agent?.report_type || '').replaceAll('_', ' ');
const reportLabel = (agent) => reportName(agent).replace(/^./, (letter) => letter.toUpperCase());
const report = computed(() => reportName(agents.value.find((agent) => agent.key === agentKey.value)));

// Where a run puts its report: the agent's existing Google Sheet, or a new one.
const sheetMode = ref('existing');
const sheetOptions = [
    { value: 'existing', label: 'Existing Google Sheet', hint: 'Every run updates the same sheet and keeps your team’s notes.' },
    { value: 'new', label: 'New Google Sheet', hint: 'Every run puts its report in a new sheet.' },
];

// The agent's PDFs collected from email; with "all" on, the agent uses every one of them.
const pdfs = ref([]);
const all = ref(true);
const selected = ref([]);

const loading = ref(true);
const switching = ref(false);
const resyncing = ref(false);
const resynced = ref(null);
const saving = ref(false);
const saved = ref(false);
const error = ref('');

const canSave = computed(() => !saving.value && !switching.value && (all.value || selected.value.length > 0));

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
            agent_key: agentKey.value,
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

// Pull new PDFs from Gmail (the last week, every sender) and refresh the list, keeping any unsaved picks.
async function resync() {
    resyncing.value = true;
    resynced.value = null;
    error.value = '';

    try {
        const { collected } = await api('POST', '/pdfs/collect', {});
        pdfs.value = (await api('GET', settingsUrl(agentKey.value))).pdfs;

        const ids = new Set(pdfs.value.map((pdf) => pdf.id));
        selected.value = selected.value.filter((id) => ids.has(id));
        resynced.value = collected;
    } catch (e) {
        handleError(e);
    } finally {
        resyncing.value = false;
    }
}

const formatDate = (value) => new Date(value).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });

const settingsUrl = (key) => (key ? `/agent-settings?agent=${encodeURIComponent(key)}` : '/agent-settings');

// Load one agent's saved settings and PDFs; unsaved changes to the agent shown before are dropped.
async function load(key) {
    const data = await api('GET', settingsUrl(key));
    agents.value = data.agents;
    agentKey.value = data.agent;
    pdfs.value = data.pdfs;
    apply(data.settings);
    saved.value = false;
    resynced.value = null;
}

async function switchAgent(key) {
    if (key === agentKey.value || switching.value) return;

    switching.value = true;
    error.value = '';

    try {
        await load(key);
    } catch (e) {
        handleError(e);
    } finally {
        switching.value = false;
    }
}

onMounted(async () => {
    try {
        await load();
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
                <p class="mt-2 text-zinc-500">Choose, for each agent, where it puts its report and which PDFs it works with.</p>
            </div>

            <div v-if="loading" class="flex items-center gap-2 text-sm text-zinc-500">
                <Spinner class="size-4 text-violet-600" /> Loading settings…
            </div>

            <form v-else @submit.prevent="save" class="animate-fade-up space-y-6">
                <section class="card p-5 sm:p-6">
                    <div class="flex items-start gap-4">
                        <span class="icon-badge bg-sky-50 text-sky-600 ring-sky-600/10">
                            <Icon name="bolt" class="size-5" />
                        </span>
                        <div>
                            <h2 class="font-semibold text-zinc-950">Report</h2>
                            <p class="mt-1 text-sm text-zinc-500">The agent whose settings you’re changing. Each one is saved on its own.</p>
                        </div>
                    </div>

                    <div role="radiogroup" aria-label="Report" class="mt-4 grid gap-3 sm:grid-cols-2">
                        <button v-for="agent in agents" :key="agent.key" type="button" role="radio"
                                :aria-checked="agentKey === agent.key" :disabled="switching" @click="switchAgent(agent.key)"
                                class="rounded-xl p-4 text-left ring-1 transition"
                                :class="agentKey === agent.key ? 'bg-violet-50/60 ring-violet-600/40' : 'bg-white ring-zinc-950/10 hover:ring-zinc-950/20'">
                            <span class="block text-sm font-medium text-zinc-950">{{ reportLabel(agent) }}</span>
                            <span class="mt-1 block text-sm text-zinc-500">{{ agent.name }}</span>
                        </button>
                    </div>
                </section>

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

                <section class="card overflow-hidden transition-opacity" :class="{ 'opacity-60': switching }">
                    <div class="flex items-start gap-4 p-5 sm:p-6">
                        <span class="icon-badge bg-rose-50 text-rose-600 ring-rose-600/10">
                            <Icon name="document" class="size-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <h2 class="font-semibold text-zinc-950 first-letter:uppercase">{{ report }} PDFs</h2>
                            <p class="mt-1 text-sm text-zinc-500">The PDFs from your email the agent may use. It builds the report from the newest of them.</p>
                        </div>
                        <button type="button" @click="resync" :disabled="resyncing" class="btn btn-secondary shrink-0"
                                title="Collect new PDFs from the last week of Gmail">
                            <Spinner v-if="resyncing" class="size-4" />
                            <Icon v-else name="refresh" class="size-4" />
                            {{ resyncing ? 'Syncing…' : 'Resync' }}
                        </button>
                    </div>

                    <p v-if="resynced !== null" class="mx-5 mb-4 rounded-lg px-3 py-2 text-xs ring-1 sm:mx-6"
                       :class="resynced ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/15' : 'bg-zinc-100 text-zinc-600 ring-zinc-950/5'">
                        {{ resynced ? `Collected ${resynced} new PDF(s) from Gmail.` : 'No new PDFs in the last week of Gmail.' }}
                    </p>

                    <label class="flex cursor-pointer items-center gap-3 border-t border-zinc-950/5 px-5 py-3 text-sm sm:px-6">
                        <input type="checkbox" v-model="all" @change="saved = false" class="size-4 cursor-pointer rounded accent-violet-600">
                        <span class="font-medium text-zinc-900">All</span>
                        <span class="text-zinc-500">every {{ report }} PDF, including ones that arrive later</span>
                    </label>

                    <p v-if="!pdfs.length" class="border-t border-zinc-950/5 px-5 py-6 text-sm text-zinc-500 sm:px-6">
                        No {{ report }} PDFs have been collected yet. Run the agent or collect PDFs in the workspace first.
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
