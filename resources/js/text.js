// "Jane Doe" → JD, "jane@example.com" → J
export function initials(value) {
    const words = (value || '?').replace(/[^\p{L}\p{N} ]/gu, ' ').trim().split(/\s+/);
    return ((words[0]?.[0] || '?') + (words.length > 1 ? words[words.length - 1][0] : '')).toUpperCase();
}
