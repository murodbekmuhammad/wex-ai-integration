import { createApp } from 'vue';
import App from './App.vue';

const el = document.getElementById('app');

createApp(App, {
    initialUser: JSON.parse(el.dataset.user),
    error: JSON.parse(el.dataset.error),
}).mount(el);
