import { computed, ref } from 'vue';
import { apiStream } from './api';

// Runs an agent endpoint and collects its streamed steps, plus the Google Sheet it created.
export function useAgentRun(onUnauthorized) {
    const log = ref('');
    const running = ref(false);
    const error = ref('');

    // The newest Google Sheet link from the app's own progress lines (indented), never from Claude's summary text.
    const sheetUrl = computed(() => {
        const links = [...log.value.matchAll(/^ {2}.*?(https:\/\/docs\.google\.com\/\S+)/gm)];
        return links.length ? links[links.length - 1][1] : null;
    });

    async function start(url, body = {}) {
        if (running.value) return false;

        running.value = true;
        log.value = '';
        error.value = '';

        try {
            await apiStream(url, body, (text) => {
                log.value += text;
            });
            return true;
        } catch (e) {
            if (e.status === 401) onUnauthorized?.();
            else error.value = Object.values(e.errors).flat()[0] || e.message;
            return false;
        } finally {
            running.value = false;
        }
    }

    return { log, running, error, sheetUrl, start };
}
