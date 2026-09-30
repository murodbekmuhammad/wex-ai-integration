<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { api, apiStream } from '../api';
import Icon from './Icon.vue';
import ReportAgent from './ReportAgent.vue';
import SavedTables from './SavedTables.vue';
import Spinner from './Spinner.vue';
import TableView from './TableView.vue';

const emit = defineEmits(['unauthorized']);

// A local calendar date as YYYY-MM-DD, the format <input type="date"> uses.
function isoDate(date) {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

const today = isoDate(new Date());
const weekAgo = new Date();
weekAgo.setDate(weekAgo.getDate() - 7);

// Collecting, listing and analysing all cover this date range; the last week by default.
const range = reactive({ from: isoDate(weekAgo), to: today });

// '' means every sender.
const sender = ref('');
const senders = ref([]);

// A key from config/report_types.php; '' means every type.
const reportType = ref('');
const reportTypes = ref([]);

const documents = ref([]);
const totalSize = ref(0);
const collecting = ref(false);
const collected = ref(null);
const classified = ref(0);
const error = ref('');

const question = ref('');
const asked = ref('');
const answer = ref('');
const analyzing = ref(false);

// Ticked PDF ids; when any are ticked, Claude reads only those.
const selected = ref([]);
const allSelected = computed(() => documents.value.length > 0 && selected.value.length === documents.value.length);

// The table on screen, and whether Claude is building or updating one.
const table = ref(null);
const building = ref(false);

// Lets the agent panel refresh the list after it saves a table.
const savedTables = ref(null);

function handleError(e) {
    if (e.status === 401) return emit('unauthorized');
    error.value = e.message;
}

function filterBody() {
    return { senders: sender.value ? [sender.value] : [], from: range.from, to: range.to, report_type: reportType.value || null };
}

// Which PDFs Claude should read: the ticked ones, or the newest matching the filters.
function sourceBody() {
    return selected.value.length ? { ...filterBody(), document_ids: selected.value } : filterBody();
}

function filterParams() {
    const params = new URLSearchParams(sender.value ? [['senders[]', sender.value]] : []);
    params.append('from', range.from);
    params.append('to', range.to);
    if (reportType.value) params.append('report_type', reportType.value);
    return params;
}

async function load() {
    error.value = '';

    try {
        const data = await api('GET', `/pdfs?${filterParams()}`);
        documents.value = data.documents;
        totalSize.value = data.total_size;
        senders.value = data.senders;
        reportTypes.value = data.report_types;

        const ids = new Set(data.documents.map((doc) => doc.id));
        selected.value = selected.value.filter((id) => ids.has(id));
    } catch (e) {
        handleError(e);
    }
}

async function collect() {
    collecting.value = true;
    collected.value = null;
    error.value = '';

    try {
        const data = await api('POST', '/pdfs/collect', filterBody());
        collected.value = data.collected;
        classified.value = data.classified;
        await load();
    } catch (e) {
        handleError(e);
    } finally {
        collecting.value = false;
    }
}

async function analyze() {
    if (!question.value.trim() || analyzing.value) return;

    analyzing.value = true;
    asked.value = question.value.trim();
    answer.value = '';
    error.value = '';

    try {
        await apiStream('/analyze', { ...sourceBody(), question: asked.value }, (text) => {
            answer.value += text;
        });
        question.value = '';
    } catch (e) {
        asked.value = '';
        handleError(e);
    } finally {
        analyzing.value = false;
    }
}

async function makeTable() {
    if (!question.value.trim() || building.value) return;

    building.value = true;
    error.value = '';

    try {
        table.value = await api('POST', '/tables', { ...sourceBody(), question: question.value.trim() });
        question.value = '';
    } catch (e) {
        handleError(e);
    } finally {
        building.value = false;
    }
}

async function reviseTable(change) {
    building.value = true;
    error.value = '';

    try {
        table.value = await api('POST', '/tables', { question: change, table_id: table.value.id });
    } catch (e) {
        handleError(e);
    } finally {
        building.value = false;
    }
}

function toggleAll() {
    selected.value = allSelected.value ? [] : documents.value.map((doc) => doc.id);
}

const formatDate = (value) => new Date(value).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
const reportTypeLabel = (key) => reportTypes.value.find((type) => type.key === key)?.label ?? key;
const formatSize = (bytes) => (bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${(bytes / 1024).toFixed(0)} KB`);

// The list and any analysis belong to the sender, dates and report type they were made for.
watch(() => [sender.value, range.from, range.to, reportType.value], () => {
    collected.value = null;
    asked.value = '';
    answer.value = '';
    selected.value = [];
    load();
});

onMounted(load);
</script>

<template>
    <div id="documents" class="scroll-mt-24 space-y-6">
        <!-- Stats -->
        <div class="grid animate-fade-up grid-cols-2 gap-3 [animation-delay:60ms] lg:grid-cols-4">
            <div class="card p-4">
                <p class="text-xs font-medium text-zinc-500">PDFs in range</p>
                <p class="mt-1 text-2xl font-semibold tracking-tight text-zinc-950 tabular-nums">{{ documents.length }}</p>
            </div>
            <div class="card p-4">
                <p class="text-xs font-medium text-zinc-500">Total size</p>
                <p class="mt-1 text-2xl font-semibold tracking-tight text-zinc-950 tabular-nums">{{ formatSize(totalSize) }}</p>
            </div>
            <div class="card p-4">
                <p class="text-xs font-medium text-zinc-500">Senders</p>
                <p class="mt-1 text-2xl font-semibold tracking-tight text-zinc-950 tabular-nums">{{ senders.length }}</p>
            </div>
            <div class="card p-4">
                <p class="text-xs font-medium text-zinc-500">Claude will read</p>
                <p class="mt-1 text-2xl font-semibold tracking-tight text-zinc-950 tabular-nums">
                    {{ selected.length || Math.min(documents.length, 20) }}
                    <span class="text-sm font-normal text-zinc-400">{{ selected.length ? 'ticked' : 'newest' }}</span>
                </p>
            </div>
        </div>

        <!-- Documents -->
        <section class="card animate-fade-up overflow-hidden [animation-delay:120ms]">
            <div class="flex flex-wrap items-center gap-4 border-b border-zinc-950/5 px-5 py-4 sm:px-6">
                <span class="icon-badge bg-rose-50 text-rose-600 ring-rose-600/10">
                    <Icon name="document" class="size-5" />
                </span>
                <div class="mr-auto">
                    <h2 class="font-semibold text-zinc-950">PDF documents</h2>
                    <p class="text-sm text-zinc-500">Attachments collected from your Gmail</p>
                </div>
                <button @click="collect" :disabled="collecting" class="btn btn-primary"
                        :title="sender ? `Fetch PDFs ${sender} mailed in this date range` : 'Fetch PDFs anyone mailed in this date range'">
                    <Spinner v-if="collecting" class="size-4" />
                    <Icon v-else name="cloud" class="size-4" />
                    {{ collecting ? 'Collecting from Gmail…' : 'Collect PDFs' }}
                </button>
            </div>

            <div class="flex flex-wrap items-end gap-3 bg-zinc-50/60 px-5 py-4 sm:px-6">
                <label class="grid gap-1.5">
                    <span class="text-xs font-medium text-zinc-500">Sender</span>
                    <span class="relative">
                        <Icon name="user" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-zinc-400" />
                        <select v-model="sender" class="field w-64 appearance-none pr-9 pl-9">
                            <option value="">All senders</option>
                            <option v-for="s in senders" :key="s" :value="s">{{ s }}</option>
                        </select>
                        <Icon name="chevron-up-down" class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-zinc-400" />
                    </span>
                </label>
                <div class="grid gap-1.5">
                    <span class="text-xs font-medium text-zinc-500">Date range</span>
                    <div class="flex items-center rounded-xl bg-white shadow-xs ring-1 ring-zinc-950/10 focus-within:ring-2 focus-within:ring-violet-500">
                        <Icon name="calendar" class="ml-3 size-4 text-zinc-400" />
                        <input type="date" v-model="range.from" :max="range.to" required aria-label="From"
                               class="border-0 bg-transparent px-2 py-2 text-sm text-zinc-900 focus:ring-0 focus:outline-none">
                        <Icon name="arrow-right" class="size-3.5 text-zinc-300" />
                        <input type="date" v-model="range.to" :min="range.from" :max="today" required aria-label="To"
                               class="border-0 bg-transparent px-2 py-2 text-sm text-zinc-900 focus:ring-0 focus:outline-none">
                    </div>
                </div>
                <div class="grid gap-1.5">
                    <span class="text-xs font-medium text-zinc-500">Report type</span>
                    <div class="flex flex-wrap gap-2" role="group" aria-label="Report type">
                        <button v-for="type in [{ key: '', label: 'All' }, ...reportTypes]" :key="type.key" type="button"
                                @click="reportType = type.key" :aria-pressed="reportType === type.key"
                                class="inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-medium ring-1 transition-colors"
                                :class="reportType === type.key
                                    ? 'bg-violet-600 text-white ring-violet-600 shadow-sm shadow-violet-500/30'
                                    : 'bg-white text-zinc-700 shadow-xs ring-zinc-950/10 hover:bg-zinc-50'">
                            <Icon name="tag" class="size-4" />
                            {{ type.label }}
                        </button>
                    </div>
                </div>

                <p v-if="collected !== null"
                   class="ml-auto inline-flex animate-fade-up items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium ring-1"
                   :class="collected ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/15' : 'bg-zinc-100 text-zinc-600 ring-zinc-950/5'">
                    <Icon name="check" class="size-4" />
                    {{ collected ? `Collected ${collected} new PDF(s)` : 'No new PDFs found' }}{{ classified ? `, tagged ${classified}` : '' }}
                </p>
            </div>

            <div v-if="error" class="mx-5 mt-4 flex gap-3 rounded-xl bg-red-50 p-3.5 text-sm text-red-700 ring-1 ring-red-600/10 sm:mx-6">
                <Icon name="warning" class="size-5 shrink-0" />
                <span>{{ error }}</span>
            </div>

            <div v-if="!documents.length" class="px-6 py-14 text-center">
                <span class="mx-auto mb-4 flex size-12 items-center justify-center rounded-2xl bg-rose-50 text-rose-500 ring-1 ring-rose-600/10">
                    <Spinner v-if="collecting" class="size-6" />
                    <Icon v-else name="document" class="size-6" />
                </span>
                <p class="font-medium text-zinc-900">{{ collecting ? 'Searching your mailbox' : 'No PDFs here yet' }}</p>
                <p class="mx-auto mt-1 max-w-sm text-sm text-zinc-500">
                    <template v-if="collecting">Looking for PDF attachments in these dates…</template>
                    <template v-else>No {{ reportType ? `${reportTypeLabel(reportType)} ` : '' }}PDFs {{ sender ? `from ${sender} ` : '' }}in these dates. Click “Collect PDFs” or widen the filters.</template>
                </p>
            </div>

            <div v-else class="scroll-thin max-h-80 overflow-auto">
                <table class="w-full text-left text-sm">
                    <thead class="sticky top-0 z-10 bg-white/90 backdrop-blur">
                        <tr class="border-b border-zinc-950/5 text-xs font-medium tracking-wide text-zinc-500 uppercase">
                            <th class="w-10 py-3 pr-2 pl-5 sm:pl-6">
                                <input type="checkbox" :checked="allSelected" @change="toggleAll" title="Tick all"
                                       class="size-4 cursor-pointer rounded accent-violet-600">
                            </th>
                            <th class="px-3 py-3 font-medium">File</th>
                            <th class="px-3 py-3 font-medium">From</th>
                            <th class="px-3 py-3 font-medium">Subject</th>
                            <th class="px-3 py-3 text-right font-medium">Size</th>
                            <th class="px-3 py-3 text-right font-medium">Date</th>
                            <th class="py-3 pr-5 pl-3 sm:pr-6"><span class="sr-only">Download</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-950/5">
                        <tr v-for="doc in documents" :key="doc.id" class="group transition-colors hover:bg-zinc-50"
                            :class="{ 'bg-violet-50/60 hover:bg-violet-50': selected.includes(doc.id) }">
                            <td class="py-2.5 pr-2 pl-5 sm:pl-6">
                                <input type="checkbox" v-model="selected" :value="doc.id" :aria-label="`Tick ${doc.filename}`"
                                       class="size-4 cursor-pointer rounded accent-violet-600">
                            </td>
                            <td class="max-w-64 px-3 py-2.5">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-7 w-6 shrink-0 items-end justify-center rounded-[5px] bg-rose-500 pb-0.5 text-[8px] font-bold text-white shadow-sm shadow-rose-500/30">PDF</span>
                                    <span class="truncate font-medium text-zinc-900" :title="doc.filename">{{ doc.filename }}</span>
                                    <span v-if="doc.report_type"
                                          class="shrink-0 rounded-full bg-violet-50 px-2 py-0.5 text-[11px] font-medium text-violet-700 ring-1 ring-violet-600/15">
                                        {{ reportTypeLabel(doc.report_type) }}
                                    </span>
                                </div>
                            </td>
                            <td class="max-w-44 truncate px-3 py-2.5 text-zinc-500" :title="doc.sender">{{ doc.sender_email }}</td>
                            <td class="max-w-64 truncate px-3 py-2.5 text-zinc-500">{{ doc.subject || '(no subject)' }}</td>
                            <td class="px-3 py-2.5 text-right whitespace-nowrap text-zinc-500 tabular-nums">{{ formatSize(doc.size) }}</td>
                            <td class="px-3 py-2.5 text-right whitespace-nowrap text-zinc-500 tabular-nums">{{ formatDate(doc.sent_at) }}</td>
                            <td class="py-2.5 pr-5 pl-3 text-right sm:pr-6">
                                <a :href="`/pdfs/${doc.id}/download`" title="Download"
                                   class="btn btn-ghost px-2 py-1.5 opacity-60 group-hover:opacity-100">
                                    <Icon name="download" class="size-4" />
                                    <span class="sr-only">Download</span>
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Claude -->
        <section v-if="documents.length" class="animate-fade-up rounded-2xl bg-linear-to-br from-violet-500/70 via-indigo-500/50 to-sky-400/60 p-px shadow-xl shadow-violet-500/10 [animation-delay:160ms]">
            <div class="rounded-[15px] bg-white">
                <form @submit.prevent="analyze">
                    <div class="flex items-center gap-2 px-5 pt-4 text-sm font-medium sm:px-6">
                        <Icon name="sparkles" class="size-4 text-violet-600" />
                        <span class="bg-linear-to-r from-violet-700 to-indigo-600 bg-clip-text text-transparent">Ask Claude</span>
                    </div>
                    <textarea v-model="question" rows="3" maxlength="2000"
                              @keydown.enter.exact.prevent="analyze"
                              placeholder="Ask about these PDFs, or describe a table to build, e.g. “Revenue by branch from the March and April reports”"
                              class="block w-full resize-none border-0 bg-transparent px-5 py-3 text-base text-zinc-900 placeholder:text-zinc-400 focus:ring-0 focus:outline-none sm:px-6"></textarea>
                    <div class="flex flex-wrap items-center gap-3 border-t border-zinc-950/5 px-5 py-3 sm:px-6">
                        <p class="mr-auto text-xs text-zinc-500">
                            <template v-if="selected.length">Claude reads the <span class="font-medium text-violet-700">{{ selected.length }} ticked PDF(s)</span>.</template>
                            <template v-else>Claude reads up to 20 of these PDFs. Tick PDFs to choose which.</template>
                            <span class="hidden sm:inline"> Press Enter to analyse.</span>
                        </p>
                        <button type="button" @click="makeTable" :disabled="building || !question.trim()" class="btn btn-secondary">
                            <Spinner v-if="building" class="size-4" />
                            <Icon v-else name="table" class="size-4" />
                            {{ building ? 'Building table…' : 'Make table' }}
                        </button>
                        <button :disabled="analyzing || !question.trim()" class="btn btn-ai">
                            <Spinner v-if="analyzing" class="size-4" />
                            <Icon v-else name="sparkles" class="size-4" />
                            {{ analyzing ? 'Reading PDFs…' : 'Analyse PDFs' }}
                        </button>
                    </div>
                </form>

                <div v-if="building && !table" class="flex items-center gap-4 border-t border-zinc-950/5 px-5 py-5 sm:px-6">
                    <span class="relative flex size-10 shrink-0 items-center justify-center rounded-xl bg-violet-50 text-violet-600">
                        <span class="absolute inset-0 animate-ping rounded-xl bg-violet-200/60"></span>
                        <Icon name="table" class="relative size-5" />
                    </span>
                    <div>
                        <p class="text-sm font-medium text-zinc-900">Building your table</p>
                        <p class="text-sm text-zinc-500">Claude is reading the PDFs. This can take a minute…</p>
                    </div>
                </div>

                <div v-if="asked" class="space-y-4 border-t border-zinc-950/5 px-5 py-5 text-sm sm:px-6">
                    <div class="flex justify-end">
                        <p class="max-w-2xl rounded-2xl rounded-br-md bg-zinc-900 px-4 py-2.5 text-white">{{ asked }}</p>
                    </div>
                    <div class="flex gap-3">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-violet-500 to-indigo-600 text-white shadow-md shadow-violet-500/30">
                            <Icon name="sparkles" class="size-4" />
                        </span>
                        <div class="min-w-0 flex-1 pt-1">
                            <div v-if="analyzing && !answer" class="flex items-center gap-1.5 py-2" aria-label="Claude is reading the documents">
                                <span class="size-2 animate-bounce rounded-full bg-violet-400"></span>
                                <span class="size-2 animate-bounce rounded-full bg-violet-400 [animation-delay:150ms]"></span>
                                <span class="size-2 animate-bounce rounded-full bg-violet-400 [animation-delay:300ms]"></span>
                            </div>
                            <p class="leading-relaxed whitespace-pre-wrap text-zinc-700">{{ answer }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <ReportAgent @done="savedTables?.load()" @unauthorized="emit('unauthorized')" />

        <TableView v-if="table" :table="table" :busy="building"
                   @revise="reviseTable" @close="table = null"
                   @unauthorized="emit('unauthorized')" />

        <SavedTables ref="savedTables" :current-id="table?.id" @open="table = $event"
                     @deleted="(id) => { if (table?.id === id) table = null; }"
                     @unauthorized="emit('unauthorized')" />
    </div>
</template>
