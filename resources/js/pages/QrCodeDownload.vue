<script setup>
import { getCurrentInstance, ref } from 'vue';
import { Head } from '@statamic/cms/inertia';
import { Header, Panel, Button } from '@statamic/cms/ui';

/**
 * The files of a vanity URL's QR code (UAMS-Web/aether-redirects#14).
 *
 * Lists the SVG, PNG, PDF and EPS files made so far for download, and makes them, or makes them again
 * in place, by POSTing to `generateUrl`. Both notices from the QR code guidance are shown here, as they
 * are on the request form.
 */
const props = defineProps({
    host: { type: String, default: '' },
    source: { type: String, default: '' },
    generateUrl: { type: String, required: true },
    guidanceUrl: { type: String, required: true },
    // [{ format, url }]
    files: { type: Array, default: () => [] },
});

const instance = getCurrentInstance().proxy;
const __ = (key) => instance.__(key);

const links = ref([...props.files]);
const busy = ref(false);
const error = ref(null);

// Make the files, or replace them in place, and show the links the server answers with.
async function generate() {
    busy.value = true;
    error.value = null;

    try {
        const { data } = await instance.$axios.post(props.generateUrl, {});

        links.value = data.files;
    } catch (failure) {
        error.value =
            failure.response && failure.response.data && failure.response.data.message
                ? failure.response.data.message
                : __('Something went wrong. Please try again.');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Head :title="__('QR Code')" />

    <div class="max-w-4xl mx-auto" data-max-width-wrapper>
        <Header :title="__('QR Code')" icon="hyperlink" />

        <Panel>
            <div class="space-y-4 p-4">
                <p class="text-sm">
                    {{ __('The code encodes') }} <code>https://{{ host }}{{ source }}-QR</code>.
                </p>

                <ul class="list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-400">
                    <li>
                        {{ __('A QR code must always be printed with the visible text URL beside it.') }}
                        <a :href="guidanceUrl" target="_blank" rel="noopener" class="underline">{{ __('QR code guidance') }}</a>
                    </li>
                    <li>
                        {{ __('Do not make your own QR code from the visible text URL, because scans would count as typed visits. Making your own from the QR URL is fine.') }}
                        <a :href="guidanceUrl" target="_blank" rel="noopener" class="underline">{{ __('QR code guidance') }}</a>
                    </li>
                </ul>

                <ul v-if="links.length" class="space-y-1 text-sm">
                    <li v-for="link in links" :key="link.format">
                        <a :href="link.url" class="underline" download>{{ link.format.toUpperCase() }}</a>
                    </li>
                </ul>
                <p v-else class="text-sm text-gray-500">{{ __('No files have been made yet.') }}</p>

                <p v-if="error" class="text-sm text-red-600" v-text="error" />

                <Button variant="primary" :disabled="busy" @click="generate">
                    {{ links.length ? __('Make the files again') : __('Make the files') }}
                </Button>
            </div>
        </Panel>
    </div>
</template>
