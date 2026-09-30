import { computed, onBeforeUnmount, ref } from 'vue';
import { apiEvents } from './api';

// Runs an agent endpoint and turns its streamed events into timeline steps.
export function useAgentRun(onUnauthorized) {
    const steps = ref([]);
    const planning = ref(false);
    const summary = ref('');
    const notice = ref(null); // { kind: 'stopped' | 'error', text }
    const sheetUrl = ref(null);
    const running = ref(false);
    const error = ref('');
    const startedAt = ref(null);
    const finishedAt = ref(null);

    // Ticks once a second while running, so durations count up live.
    const now = ref(Date.now());
    let timer = null;

    const elapsed = computed(() => (startedAt.value ? (finishedAt.value ?? now.value) - startedAt.value : 0));
    const hasActivity = computed(() => running.value || steps.value.length > 0 || summary.value || notice.value);

    function handle(event) {
        const at = Date.now();

        if (event.type === 'thinking') {
            planning.value = true;
        } else if (event.type === 'step') {
            planning.value = false;
            steps.value.push({ tool: event.tool, label: event.label, status: 'running', text: '', startedAt: at, finishedAt: null });
        } else if (event.type === 'result') {
            const step = [...steps.value].reverse().find((s) => s.tool === event.tool && s.status === 'running');
            if (step) Object.assign(step, { status: event.ok ? 'done' : 'failed', text: event.text, finishedAt: at });
            if (event.link) sheetUrl.value = event.link;
        } else if (event.type === 'summary') {
            planning.value = false;
            summary.value = event.text;
        } else if (event.type === 'stopped' || event.type === 'error') {
            planning.value = false;
            notice.value = { kind: event.type, text: event.text };
        }
    }

    async function start(url, body = {}) {
        if (running.value) return false;

        steps.value = [];
        planning.value = false;
        summary.value = '';
        notice.value = null;
        sheetUrl.value = null;
        error.value = '';
        startedAt.value = Date.now();
        finishedAt.value = null;
        running.value = true;
        now.value = Date.now();
        timer = setInterval(() => (now.value = Date.now()), 1000);

        try {
            await apiEvents(url, body, handle);
            return !notice.value;
        } catch (e) {
            if (e.status === 401) onUnauthorized?.();
            else error.value = Object.values(e.errors || {}).flat()[0] || e.message;
            return false;
        } finally {
            planning.value = false;
            running.value = false;
            finishedAt.value = Date.now();
            clearInterval(timer);
        }
    }

    onBeforeUnmount(() => clearInterval(timer));

    return { steps, planning, summary, notice, sheetUrl, running, error, elapsed, now, hasActivity, start };
}
