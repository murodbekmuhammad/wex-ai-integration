// Tiny fetch wrapper for talking to Laravel with session cookies.
// Laravel sets an XSRF-TOKEN cookie on every response; echoing it back
// in the X-XSRF-TOKEN header satisfies CSRF protection.

function xsrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function request(method, url, body) {
    return fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: body ? JSON.stringify(body) : undefined,
    });
}

async function toError(response) {
    const data = await response.json().catch(() => ({}));
    const error = new Error(data.message || `Request failed (${response.status})`);
    error.status = response.status;
    error.errors = data.errors || {};
    return error;
}

export async function api(method, url, body) {
    const response = await request(method, url, body);

    if (!response.ok) throw await toError(response);

    return response.json().catch(() => ({}));
}

// POST and read a plain-text streamed response, calling onText for each piece as it arrives.
export async function apiStream(url, body, onText) {
    const response = await request('POST', url, body);

    if (!response.ok) throw await toError(response);

    const reader = response.body.getReader();
    const decoder = new TextDecoder();

    for (;;) {
        const { done, value } = await reader.read();
        if (done) break;
        onText(decoder.decode(value, { stream: true }));
    }
}

// POST and read a newline-delimited JSON stream, calling onEvent with each parsed event as it arrives.
export async function apiEvents(url, body, onEvent) {
    let buffer = '';

    const flush = (final) => {
        const lines = buffer.split('\n');
        buffer = final ? '' : lines.pop();

        for (const line of lines) {
            if (!line.trim()) continue;

            let event;
            try {
                event = JSON.parse(line);
            } catch {
                throw new Error('The server sent an unexpected response. Please try again.');
            }
            onEvent(event);
        }
    };

    await apiStream(url, body, (text) => {
        buffer += text;
        flush(false);
    });

    flush(true);
}
