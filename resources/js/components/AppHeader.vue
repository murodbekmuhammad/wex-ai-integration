<script setup>
import { api } from '../api';
import { initials } from '../text';
import AppLogo from './AppLogo.vue';
import Icon from './Icon.vue';

defineProps({ user: Object, page: String });
const emit = defineEmits(['logout']);

const pages = [
    { key: 'agents', label: 'Agents', href: '/' },
    { key: 'settings', label: 'Agent settings', href: '/settings' },
    { key: 'workspace', label: 'Workspace', href: '/workspace' },
];

async function logout() {
    await api('POST', '/logout');
    emit('logout');
}
</script>

<template>
    <header class="sticky top-0 z-30 border-b border-zinc-950/5 bg-white/70 backdrop-blur-xl">
        <div class="mx-auto flex h-16 max-w-7xl items-center gap-6 px-4 sm:px-6">
            <a href="/" class="flex items-center gap-2.5">
                <AppLogo class="size-8" />
                <span class="text-lg font-semibold tracking-tight">Wex</span>
            </a>

            <nav class="flex items-center gap-1 text-sm font-medium text-zinc-500">
                <a v-for="item in pages" :key="item.key" :href="item.href"
                   :aria-current="item.key === page ? 'page' : null"
                   :class="item.key === page ? 'bg-zinc-950/5 text-zinc-900' : 'hover:bg-zinc-950/5 hover:text-zinc-900'"
                   class="rounded-lg px-3 py-1.5 transition">{{ item.label }}</a>
            </nav>

            <div class="ml-auto flex items-center gap-3">
                <slot />

                <div class="flex items-center gap-2.5 border-l border-zinc-950/10 pl-3">
                    <span class="flex size-8 items-center justify-center rounded-full bg-linear-to-br from-zinc-800 to-zinc-950 text-xs font-semibold text-white ring-2 ring-white">
                        {{ initials(user.name || user.email) }}
                    </span>
                    <div class="hidden leading-tight lg:block">
                        <p class="text-sm font-medium text-zinc-900">{{ user.name }}</p>
                        <p class="text-xs text-zinc-500">{{ user.email }}</p>
                    </div>
                    <button @click="logout" title="Log out" class="btn btn-ghost px-2">
                        <Icon name="logout" class="size-5" />
                        <span class="sr-only">Log out</span>
                    </button>
                </div>
            </div>
        </div>
    </header>
</template>
