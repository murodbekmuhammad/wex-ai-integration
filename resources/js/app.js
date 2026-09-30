import { createApp } from 'vue';
import App from './App.vue';

const el = document.getElementById('app');

createApp(App, {
    initialUser: JSON.parse(el.dataset.user),
    error: JSON.parse(el.dataset.error),
    page: JSON.parse(el.dataset.page),
}).mount(el);
