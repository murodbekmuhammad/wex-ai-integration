<script setup>
import { ref } from 'vue';
import SignIn from './components/SignIn.vue';
import Inbox from './components/Inbox.vue';

const props = defineProps({ initialUser: Object, error: String });

const user = ref(props.initialUser);
</script>

<template>
    <Inbox v-if="user" :user="user" @logout="user = null" />
    <SignIn v-else :error="error" />
</template>
