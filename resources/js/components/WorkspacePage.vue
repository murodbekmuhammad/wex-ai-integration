<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import { api } from '../api';
import { initials } from '../text';
import AppHeader from './AppHeader.vue';
import AskClaude from './AskClaude.vue';
import Documents from './Documents.vue';
import Icon from './Icon.vue';
import Spinner from './Spinner.vue';

defineProps({ user: Object });
const emit = defineEmits(['logout']);

const columns = [
    { key: 'sender', label: 'From' },
    { key: 'receivers', label: 'To' },
    { key: 'subject', label: 'Subject' },
    { key: 'sent_at', label: 'Date' },
];

const filters = reactive({ senders: [], receiver: '', sort: 'sent_at', direction: 'desc', page: 1 });
const emails = ref([]);
const senders = ref([]);
const receivers = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(false);
const syncing = ref(false);
const error = ref('');

// The open message: { id, loading, html, text }
const open = ref(null);

function handleError(e) {
    if (e.status === 401) return emit('logout');
    error.value = e.message;
}

// Array filters are repeated as "key[]=value" so Laravel reads them as an array.
function queryString() {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(filters)) {
        if (Array.isArray(value)) {
            value.forEach((item) => params.append(`${key}[]`, item));
        } else if (value !== '') {
            params.append(key, value);
        }
    }

    return params;
}

async function load() {
    loading.value = true;
    error.value = '';

    try {
        const data = await api('GET', `/emails?${queryString()}`);
        emails.value = data.emails.data;
        senders.value = data.senders;
        receivers.value = data.receivers;
        meta.value = data.emails;
    } catch (e) {
        handleError(e);
    } finally {
        loading.value = false;
    }
}

async function sync() {
    syncing.value = true;
    error.value = '';

    try {
        await api('POST', '/emails/sync');
        await load();
    } catch (e) {
        handleError(e);
    } finally {
        syncing.value = false;
    }
}

async function toggle(email) {
    if (open.value?.id === email.id) {
        open.value = null;
        return;
    }

    open.value = { id: email.id, loading: true };

    try {
        const body = await api('GET', `/emails/${email.id}`);
        if (open.value?.id === email.id) open.value = { id: email.id, ...body };
    } catch (e) {
        open.value = null;
        handleError(e);
    }
}

function sortBy(key) {
    if (filters.sort === key) {
        filters.direction = filters.direction === 'asc' ? 'desc' : 'asc';
    } else {
        filters.sort = key;
        filters.direction = key === 'sent_at' ? 'desc' : 'asc';
    }
}


// "Jane Doe" <jane@example.com> → Jane Doe
const displayName = (sender) => sender.replace(/<[^>]*>/, '').replaceAll('"', '').trim() || sender;

const avatarColors = [
    'bg-violet-100 text-violet-700', 'bg-sky-100 text-sky-700', 'bg-emerald-100 text-emerald-700',
    'bg-amber-100 text-amber-700', 'bg-rose-100 text-rose-700', 'bg-indigo-100 text-indigo-700',
    'bg-teal-100 text-teal-700', 'bg-fuchsia-100 text-fuchsia-700',
];

// The same sender always gets the same colour.
const avatarColor = (value) => {
    let hash = 0;
    for (const char of value || '') hash = (hash * 31 + char.charCodeAt(0)) | 0;
    return avatarColors[Math.abs(hash) % avatarColors.length];
};

// Time for today's mail, a short date otherwise.
const formatDate = (value) => {
    const date = new Date(value);
    const now = new Date();

    if (date.toDateString() === now.toDateString()) {
        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    return date.toLocaleDateString([], {
        month: 'short',
        day: 'numeric',
        ...(date.getFullYear() !== now.getFullYear() ? { year: 'numeric' } : {}),
    });
};
const fullDate = (value) => new Date(value).toLocaleString();

// Links in the email open in a new tab instead of inside the frame.
const srcdoc = (html) => `<base target="_blank">${html}`;

// Any filter/sort change other than paging jumps back to page 1.
watch(() => [filters.senders, filters.receiver, filters.sort, filters.direction], () => {
    if (filters.page !== 1) filters.page = 1;
    else load();
});
watch(() => filters.page, load);

onMounted(async () => {
    await load();   // show what we already have straight away
    await sync();   // then pull anything new from Gmail
});
</script>

<template>
    <div class="relative min-h-screen">
        <!-- Soft colour wash behind the page -->
        <div class="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[34rem] overflow-hidden" aria-hidden="true">
            <div class="absolute -top-48 left-1/4 size-[36rem] rounded-full bg-violet-300/30 blur-3xl"></div>
            <div class="absolute -top-32 right-0 size-[30rem] rounded-full bg-sky-200/40 blur-3xl"></div>
        </div>

        <AppHeader :user="user" page="workspace" @logout="emit('logout')">
            <span class="hidden items-center gap-2 rounded-full bg-white px-3 py-1 text-xs font-medium text-zinc-600 ring-1 ring-zinc-950/10 sm:inline-flex">
                <template v-if="syncing">
                    <Spinner class="size-3.5 text-violet-600" /> Syncing Gmail
                </template>
                <template v-else>
                    <span class="relative flex size-2">
                        <span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-60"></span>
                        <span class="relative inline-flex size-2 rounded-full bg-emerald-500"></span>
                    </span>
                    Up to date
                </template>
            </span>
        </AppHeader>

        <main class="mx-auto max-w-7xl space-y-8 px-4 py-10 sm:px-6">
            <div class="animate-fade-up">
                <h1 class="text-3xl font-semibold tracking-tight text-zinc-950 sm:text-4xl">Workspace</h1>
                <p class="mt-2 text-zinc-500">Collect PDFs from Gmail, ask Claude what’s inside, and turn the answers into tables.</p>
            </div>

<!--        <div class="mb-4 rounded-xl bg-white p-4 shadow">-->
<!--            <div class="flex flex-wrap items-start gap-4">-->
<!--                <div>-->
<!--                    <div class="mb-1 flex items-center gap-2">-->
<!--                        <label class="text-sm text-gray-600" for="senders">Senders</label>-->
<!--                        <button v-if="filters.senders.length" @click="filters.senders = []"-->
<!--                                class="text-xs text-indigo-600 hover:underline">Clear</button>-->
<!--                    </div>-->
<!--                    &lt;!&ndash; Hold ⌘/Ctrl to pick several senders; the email list and "Ask Claude" follow this selection. &ndash;&gt;-->
<!--                    <select id="senders" v-model="filters.senders" multiple size="4"-->
<!--                            class="w-72 rounded-lg border bg-white px-2 py-1 text-sm">-->
<!--                        <option v-for="s in senders" :key="s" :value="s">{{ s }}</option>-->
<!--                    </select>-->
<!--                    <p class="mt-1 text-xs text-gray-500">-->
<!--                        {{ filters.senders.length ? `${filters.senders.length} selected` : 'All senders' }}-->
<!--                    </p>-->
<!--                </div>-->

<!--                <div>-->
<!--                    <label class="mb-1 block text-sm text-gray-600" for="receiver">Receiver</label>-->
<!--                    <select id="receiver" v-model="filters.receiver" class="w-64 rounded-lg border bg-white px-3 py-1.5 text-sm">-->
<!--                        <option value="">All receivers</option>-->
<!--                        <option v-for="r in receivers" :key="r" :value="r">{{ r }}</option>-->
<!--                    </select>-->
<!--                </div>-->

<!--                <div class="ml-auto flex items-center gap-3">-->
<!--                    <span class="text-sm text-gray-500">{{ meta.total }} emails</span>-->
<!--                    <button @click="sync" :disabled="syncing"-->
<!--                            class="rounded-lg border bg-white px-3 py-1.5 text-sm hover:bg-gray-100 disabled:opacity-50">-->
<!--                        {{ syncing ? 'Syncing with Gmail…' : 'Refresh' }}-->
<!--                    </button>-->
<!--                </div>-->
<!--            </div>-->
<!--        </div>-->

<!--        <AskClaude :senders="filters.senders" :receiver="filters.receiver" @unauthorized="emit('logout')" />-->

            <Documents @unauthorized="emit('logout')" />

            <section id="inbox" class="card scroll-mt-24 animate-fade-up overflow-hidden [animation-delay:200ms]">
                <div class="flex flex-wrap items-center gap-4 border-b border-zinc-950/5 px-5 py-4 sm:px-6">
                    <span class="icon-badge bg-sky-50 text-sky-600 ring-sky-600/10">
                        <Icon name="inbox" class="size-5" />
                    </span>
                    <div>
                        <h2 class="font-semibold text-zinc-950">Inbox</h2>
                        <p class="text-sm text-zinc-500">{{ meta.total.toLocaleString() }} emails synced from Gmail</p>
                    </div>
                    <button @click="sync" :disabled="syncing" class="btn btn-secondary ml-auto">
                        <Spinner v-if="syncing" class="size-4" />
                        <Icon v-else name="refresh" class="size-4" />
                        {{ syncing ? 'Syncing…' : 'Refresh' }}
                    </button>
                </div>

                <div v-if="error" class="mx-5 mt-4 flex gap-3 rounded-xl bg-red-50 p-3.5 text-sm text-red-700 ring-1 ring-red-600/10 sm:mx-6">
                    <Icon name="warning" class="size-5 shrink-0" />
                    <span>{{ error }}</span>
                </div>

                <div class="scroll-thin overflow-x-auto transition-opacity" :class="{ 'opacity-60': loading && emails.length }">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-zinc-950/5 text-xs font-medium tracking-wide text-zinc-500 uppercase">
                                <th v-for="col in columns" :key="col.key" class="px-5 py-3 first:pl-5 sm:first:pl-6"
                                    :class="{ 'text-right': col.key === 'sent_at' }">
                                    <button @click="sortBy(col.key)"
                                            class="group inline-flex items-center gap-1 uppercase transition hover:text-zinc-900"
                                            :class="{ 'text-zinc-900': filters.sort === col.key }">
                                        {{ col.label }}
                                        <Icon v-if="filters.sort === col.key"
                                              :name="filters.direction === 'asc' ? 'arrow-up' : 'arrow-down'" class="size-3.5 text-violet-600" />
                                        <Icon v-else name="chevron-up-down" class="size-3.5 opacity-0 transition group-hover:opacity-100" />
                                    </button>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-950/5">
                            <template v-if="loading && !emails.length">
                                <tr v-for="n in 6" :key="`skeleton-${n}`">
                                    <td class="px-5 py-3.5 sm:pl-6">
                                        <div class="flex items-center gap-3">
                                            <div class="size-9 animate-pulse rounded-full bg-zinc-100"></div>
                                            <div class="h-3 w-28 animate-pulse rounded bg-zinc-100"></div>
                                        </div>
                                    </td>
                                    <td class="px-5"><div class="h-3 w-32 animate-pulse rounded bg-zinc-100"></div></td>
                                    <td class="px-5"><div class="h-3 w-80 animate-pulse rounded bg-zinc-100"></div></td>
                                    <td class="px-5"><div class="ml-auto h-3 w-14 animate-pulse rounded bg-zinc-100"></div></td>
                                </tr>
                            </template>

                            <template v-for="email in emails" :key="email.id">
                                <tr @click="toggle(email)" class="group cursor-pointer transition-colors hover:bg-zinc-50"
                                    :class="{ 'bg-violet-50/60 hover:bg-violet-50': open?.id === email.id }">
                                    <td class="max-w-56 py-3 pr-5 pl-5 sm:pl-6">
                                        <div class="flex items-center gap-3">
                                            <span class="flex size-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                                                  :class="avatarColor(email.sender_email || email.sender)">
                                                {{ initials(displayName(email.sender)) }}
                                            </span>
                                            <span class="truncate font-medium text-zinc-900" :title="email.sender">{{ displayName(email.sender) }}</span>
                                        </div>
                                    </td>
                                    <td class="max-w-48 truncate px-5 py-3 text-zinc-500" :title="email.receivers.join(', ')">{{ email.receivers.join(', ') }}</td>
                                    <td class="max-w-xl truncate px-5 py-3">
                                        <span class="font-medium text-zinc-900">{{ email.subject || '(no subject)' }}</span>
                                        <span class="text-zinc-400"> — {{ email.snippet }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-right whitespace-nowrap text-zinc-500 tabular-nums" :title="fullDate(email.sent_at)">
                                        {{ formatDate(email.sent_at) }}
                                    </td>
                                </tr>
                                <tr v-if="open?.id === email.id" class="bg-zinc-50/80">
                                    <td colspan="4" class="p-4 sm:px-6">
                                        <div v-if="open.loading" class="flex items-center gap-2 py-6 text-zinc-500">
                                            <Spinner class="size-4 text-violet-600" /> Loading message…
                                        </div>
                                        <!-- Sandboxed: the email's HTML can't run scripts or touch this page. -->
                                        <iframe v-else-if="open.html" :srcdoc="srcdoc(open.html)"
                                                sandbox="allow-popups allow-popups-to-escape-sandbox"
                                                class="h-[32rem] w-full animate-fade-up resize-y rounded-xl bg-white shadow-sm ring-1 ring-zinc-950/10"></iframe>
                                        <div v-else class="animate-fade-up rounded-xl bg-white p-5 whitespace-pre-line text-zinc-700 shadow-sm ring-1 ring-zinc-950/10">{{ open.text || '(empty message)' }}</div>
                                    </td>
                                </tr>
                            </template>

                            <tr v-if="!loading && !emails.length">
                                <td colspan="4" class="px-6 py-16 text-center">
                                    <span class="mx-auto mb-4 flex size-12 items-center justify-center rounded-2xl bg-zinc-100 text-zinc-400">
                                        <Spinner v-if="syncing" class="size-6 text-violet-600" />
                                        <Icon v-else name="inbox" class="size-6" />
                                    </span>
                                    <p class="font-medium text-zinc-900">{{ syncing ? 'Fetching your emails' : 'No emails found' }}</p>
                                    <p class="mt-1 text-sm text-zinc-500">{{ syncing ? 'Pulling the newest messages from Gmail…' : 'Try refreshing to pull new mail from Gmail.' }}</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <nav v-if="meta.last_page > 1" class="flex items-center justify-between gap-3 border-t border-zinc-950/5 px-5 py-3 text-sm sm:px-6">
                    <span class="text-zinc-500">Page <span class="font-medium text-zinc-900">{{ meta.current_page }}</span> of {{ meta.last_page }}</span>
                    <div class="flex gap-2">
                        <button :disabled="filters.page <= 1" @click="filters.page--" class="btn btn-secondary px-2.5">
                            <Icon name="chevron-left" class="size-4" /> Previous
                        </button>
                        <button :disabled="filters.page >= meta.last_page" @click="filters.page++" class="btn btn-secondary px-2.5">
                            Next <Icon name="chevron-right" class="size-4" />
                        </button>
                    </div>
                </nav>
            </section>
        </main>
    </div>
</template>
