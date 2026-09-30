<script setup>
import { ref } from 'vue';
import AgentsPage from './components/AgentsPage.vue';
import SignIn from './components/SignIn.vue';
import WorkspacePage from './components/WorkspacePage.vue';

const props = defineProps({ initialUser: Object, error: String, page: String });

const user = ref(props.initialUser);
</script>

<template>
    <SignIn v-if="!user" :error="error" />
    <WorkspacePage v-else-if="page === 'workspace'" :user="user" @logout="user = null" />
    <AgentsPage v-else :user="user" @logout="user = null" />
</template>
