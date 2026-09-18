<script setup>
import AppLogo from './AppLogo.vue';
import Icon from './Icon.vue';

defineProps({ error: String });

const features = [
    { icon: 'inbox', title: 'Gmail, synced', text: 'Your newest messages, sorted and filtered in one place.' },
    { icon: 'document', title: 'PDFs, collected', text: 'Every attachment in a date range pulled out automatically.' },
    { icon: 'sparkles', title: 'Claude, reading', text: 'Ask questions across dozens of documents at once.' },
    { icon: 'table', title: 'Tables, exported', text: 'Excel, PDF or Google Sheets in a single click.' },
];
</script>

<template>
    <div class="grid min-h-screen lg:grid-cols-[1.1fr_1fr]">
        <!-- Brand panel -->
        <aside class="relative hidden overflow-hidden bg-zinc-950 p-12 text-white lg:flex lg:flex-col">
            <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                <div class="absolute -top-32 -left-24 size-[28rem] animate-float rounded-full bg-violet-600/40 blur-3xl"></div>
                <div class="absolute top-1/3 -right-32 size-[26rem] animate-float rounded-full bg-indigo-500/30 blur-3xl [animation-delay:-5s]"></div>
                <div class="absolute -bottom-40 left-1/4 size-[30rem] animate-float rounded-full bg-sky-500/20 blur-3xl [animation-delay:-9s]"></div>
                <div class="absolute inset-0 bg-[linear-gradient(to_right,rgb(255_255_255/0.04)_1px,transparent_1px),linear-gradient(to_bottom,rgb(255_255_255/0.04)_1px,transparent_1px)] bg-[size:48px_48px] [mask-image:radial-gradient(ellipse_at_center,black_30%,transparent_75%)]"></div>
            </div>

            <div class="relative flex items-center gap-3">
                <AppLogo class="size-10" />
                <span class="text-xl font-semibold tracking-tight">Wex</span>
            </div>

            <div class="relative my-auto max-w-lg py-16">
                <p class="mb-5 inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-medium text-violet-200 ring-1 ring-white/15 backdrop-blur">
                    <Icon name="bolt" class="size-3.5" /> Powered by Claude
                </p>
                <h1 class="text-5xl leading-[1.05] font-semibold tracking-tight text-balance">
                    Your inbox, turned into
                    <span class="bg-linear-to-r from-violet-300 via-indigo-200 to-sky-300 bg-clip-text text-transparent">answers.</span>
                </h1>
                <p class="mt-5 text-lg text-zinc-400">
                    Collect the reports buried in your email, ask what’s inside them, and walk away with a finished spreadsheet.
                </p>

                <ul class="mt-10 grid grid-cols-2 gap-3">
                    <li v-for="(feature, i) in features" :key="feature.title"
                        class="animate-fade-up rounded-2xl bg-white/[0.04] p-4 ring-1 ring-white/10 backdrop-blur-sm transition hover:bg-white/[0.07]"
                        :style="{ animationDelay: `${150 + i * 90}ms` }">
                        <Icon :name="feature.icon" class="mb-3 size-5 text-violet-300" />
                        <p class="text-sm font-semibold">{{ feature.title }}</p>
                        <p class="mt-1 text-xs leading-relaxed text-zinc-400">{{ feature.text }}</p>
                    </li>
                </ul>
            </div>

            <p class="relative text-xs text-zinc-500">© {{ new Date().getFullYear() }} Wex</p>
        </aside>

        <!-- Sign-in -->
        <main class="relative flex items-center justify-center overflow-hidden p-6">
            <div class="pointer-events-none absolute -top-40 right-0 size-96 rounded-full bg-violet-200/50 blur-3xl lg:hidden" aria-hidden="true"></div>

            <div class="relative w-full max-w-sm animate-fade-up">
                <AppLogo class="mb-8 size-12 lg:hidden" />

                <h2 class="text-3xl font-semibold tracking-tight">Welcome back</h2>
                <p class="mt-2 text-zinc-500">Sign in with your Google account to pick up where you left off.</p>

                <div v-if="error" class="mt-6 flex gap-3 rounded-xl bg-red-50 p-3.5 text-sm text-red-700 ring-1 ring-red-600/10">
                    <Icon name="warning" class="size-5 shrink-0" />
                    <span>{{ error }}</span>
                </div>

                <a href="/auth/google"
                   class="group mt-8 flex w-full items-center justify-center gap-3 rounded-xl bg-white px-4 py-3 font-medium text-zinc-800 shadow-sm ring-1 ring-zinc-950/10 transition hover:-translate-y-0.5 hover:shadow-lg hover:shadow-zinc-950/5 active:translate-y-0">
                    <svg viewBox="0 0 48 48" class="size-5" aria-hidden="true">
                        <path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.8 2.4 30.3 0 24 0 14.6 0 6.6 5.4 2.7 13.3l7.9 6.2C12.5 13.6 17.8 9.5 24 9.5z"/>
                        <path fill="#4285F4" d="M46.1 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.4c-.5 2.9-2.2 5.3-4.6 7l7.4 5.7c4.3-4 6.9-9.9 6.9-17.2z"/>
                        <path fill="#FBBC05" d="M10.5 28.6c-.5-1.4-.8-3-.8-4.6s.3-3.2.8-4.6l-7.9-6.2C1 16.6 0 20.2 0 24s1 7.4 2.7 10.7l7.8-6.1z"/>
                        <path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.8-5.8l-7.4-5.7c-2.1 1.4-4.8 2.3-8.4 2.3-6.2 0-11.5-4.1-13.4-9.8l-7.9 6.1C6.6 42.6 14.6 48 24 48z"/>
                    </svg>
                    Continue with Google
                    <Icon name="arrow-right" class="size-4 -translate-x-1 text-zinc-400 opacity-0 transition group-hover:translate-x-0 group-hover:opacity-100" />
                </a>

                <p class="mt-6 flex items-start gap-2 text-xs leading-relaxed text-zinc-500">
                    <Icon name="lock" class="mt-px size-4 shrink-0" />
                    Wex only reads your mail, and only creates the Drive files you choose to export.
                </p>
            </div>
        </main>
    </div>
</template>
