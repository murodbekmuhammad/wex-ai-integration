<script setup>
import { computed, ref } from 'vue';
import { apiStream } from '../api';
import Icon from './Icon.vue';
import Spinner from './Spinner.vue';

const props = defineProps({ senders: Array, receiver: String });
const emit = defineEmits(['unauthorized']);

const question = ref('');
const asked = ref('');
const answer = ref('');
const asking = ref(false);
const error = ref('');

// Describes which emails the current filters hand to Claude.
const scope = computed(() => {
    const parts = [];
    if (props.senders?.length) parts.push(`from ${props.senders.length} selected sender(s)`);
    if (props.receiver) parts.push(`sent to ${props.receiver}`);

    return parts.length ? `emails ${parts.join(' and ')}` : 'your synced emails';
});

async function ask() {
    if (!question.value.trim() || asking.value) return;

    asking.value = true;
    asked.value = question.value.trim();
    answer.value = '';
    error.value = '';

    try {
        const body = { question: asked.value, senders: props.senders || [], receiver: props.receiver || null };

        await apiStream('/ask', body, (text) => {
            answer.value += text;
        });
        question.value = '';
    } catch (e) {
        if (e.status === 401) return emit('unauthorized');
        error.value = e.message;
    } finally {
        asking.value = false;
    }
}
</script>

<template>
    <section class="rounded-2xl bg-linear-to-br from-violet-500/70 via-indigo-500/50 to-sky-400/60 p-px shadow-xl shadow-violet-500/10">
        <div class="rounded-[15px] bg-white">
            <form @submit.prevent="ask">
                <textarea v-model="question" rows="2" maxlength="2000"
                          @keydown.enter.exact.prevent="ask"
                          placeholder="Ask Claude about your emails, e.g. “Which emails need a reply?”"
                          class="block w-full resize-none border-0 bg-transparent px-5 pt-4 pb-2 text-base text-zinc-900 placeholder:text-zinc-400 focus:ring-0 focus:outline-none"></textarea>
                <div class="flex flex-wrap items-center gap-3 border-t border-zinc-950/5 px-5 py-3">
                    <p class="mr-auto text-xs text-zinc-500">
                        Claude reads the sender, receivers, date, subject and snippet of {{ scope }}, not their full text.
                    </p>
                    <button :disabled="asking || !question.trim()" class="btn btn-ai">
                        <Spinner v-if="asking" class="size-4" />
                        <Icon v-else name="sparkles" class="size-4" />
                        {{ asking ? 'Thinking…' : 'Ask Claude' }}
                    </button>
                </div>
            </form>

            <div v-if="error" class="mx-5 mb-4 flex gap-3 rounded-xl bg-red-50 p-3.5 text-sm text-red-700 ring-1 ring-red-600/10">
                <Icon name="warning" class="size-5 shrink-0" />
                <span>{{ error }}</span>
            </div>

            <div v-if="asked && !error" class="space-y-4 border-t border-zinc-950/5 px-5 py-5 text-sm">
                <div class="flex justify-end">
                    <p class="max-w-2xl rounded-2xl rounded-br-md bg-zinc-900 px-4 py-2.5 text-white">{{ asked }}</p>
                </div>
                <div class="flex gap-3">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-violet-500 to-indigo-600 text-white">
                        <Icon name="sparkles" class="size-4" />
                    </span>
                    <div class="min-w-0 flex-1 pt-1">
                        <div v-if="asking && !answer" class="flex items-center gap-1.5 py-2" aria-label="Claude is reading your emails">
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
</template>
