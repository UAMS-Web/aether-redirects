<script setup>
import { getCurrentInstance, ref } from 'vue';
import { Head } from '@statamic/cms/inertia';
import { Header, Panel, Button } from '@statamic/cms/ui';

/**
 * Marketing-redirect creation screen (UAMS-Web/uams-statamic-archive#1355).
 *
 * A staff-only form that creates a vanity/marketing redirect on the shared
 * `redirects` global. On submit it POSTs to the controller's `store` endpoint,
 * which normalizes the source, rejects a source collision within an overlapping
 * validity window (UAMS-Web/uams-statamic-archive#1356), and persists the redirect so it serves on a 404 for
 * every site (per UAMS-Web/uams-statamic-archive#1110). Field errors returned as a 422 are shown inline.
 */
const props = defineProps({
    // CP endpoint that validates + persists the redirect
    storeUrl: { type: String, required: true },
    // Selectable HTTP redirect types ([{ value, label }])
    responseOptions: { type: Array, default: () => [] },
});

const instance = getCurrentInstance().proxy;
const __ = (key) => instance.__(key);

// A blank submission, reused to reset the form after a successful create.
const blankForm = () => ({
    source: '',
    destination: '',
    response: '301',
    valid_from: '',
    valid_until: '',
    requester: '',
    notes: '',
});

const form = ref(blankForm());
const errors = ref({});
const saving = ref(false);
const successMessage = ref(null);

const inputClass =
    'w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm ' +
    'focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 ' +
    'dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100';

// The first error message for a field, or null when the field is clean.
function errorFor(field) {
    const messages = errors.value[field];

    return Array.isArray(messages) && messages.length ? messages[0] : null;
}

async function submit() {
    saving.value = true;
    errors.value = {};
    successMessage.value = null;

    try {
        const { data } = await instance.$axios.post(props.storeUrl, form.value);

        // Report success and clear the form for the next redirect.
        successMessage.value = data.message;
        form.value = blankForm();
    } catch (error) {
        // A 422 carries per-field validation / collision messages to show inline.
        if (error.response && error.response.status === 422) {
            errors.value = error.response.data.errors || {};
        } else {
            errors.value = { source: [__('Something went wrong. Please try again.')] };
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Head :title="__('Marketing Redirects')" />

    <div class="max-w-3xl mx-auto" data-max-width-wrapper>
        <Header :title="__('Marketing Redirects')" icon="hyperlink" />

        <Panel>
            <form class="p-4 space-y-6" @submit.prevent="submit">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Create a redirect from a short/vanity path to a destination. It serves on a 404 for every site.') }}
                </p>

                <div
                    v-if="successMessage"
                    class="rounded-md border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-700 dark:bg-green-900/40 dark:text-green-200"
                    v-text="successMessage"
                />

                <div>
                    <label class="mb-1 block text-sm font-medium" for="mr-source">{{ __('Source path') }}</label>
                    <input
                        id="mr-source"
                        v-model="form.source"
                        type="text"
                        :class="inputClass"
                        placeholder="/flu-shot"
                        autocomplete="off"
                    />
                    <p class="mt-1 text-xs text-gray-500">{{ __('The path to redirect from, relative to the site root.') }}</p>
                    <p v-if="errorFor('source')" class="mt-1 text-xs text-red-600" v-text="errorFor('source')" />
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium" for="mr-destination">{{ __('Destination URL') }}</label>
                    <input
                        id="mr-destination"
                        v-model="form.destination"
                        type="text"
                        :class="inputClass"
                        placeholder="/clinics/flu"
                        autocomplete="off"
                    />
                    <p class="mt-1 text-xs text-gray-500">{{ __('Where to send the visitor — a relative path or an absolute URL.') }}</p>
                    <p v-if="errorFor('destination')" class="mt-1 text-xs text-red-600" v-text="errorFor('destination')" />
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium" for="mr-response">{{ __('Redirect type') }}</label>
                    <select id="mr-response" v-model="form.response" :class="inputClass">
                        <option v-for="option in responseOptions" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                    <p v-if="errorFor('response')" class="mt-1 text-xs text-red-600" v-text="errorFor('response')" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="mr-valid-from">{{ __('Valid from') }}</label>
                        <input id="mr-valid-from" v-model="form.valid_from" type="date" :class="inputClass" />
                        <p class="mt-1 text-xs text-gray-500">{{ __('Optional. Leave blank to serve immediately.') }}</p>
                        <p v-if="errorFor('valid_from')" class="mt-1 text-xs text-red-600" v-text="errorFor('valid_from')" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="mr-valid-until">{{ __('Valid until') }}</label>
                        <input id="mr-valid-until" v-model="form.valid_until" type="date" :class="inputClass" />
                        <p class="mt-1 text-xs text-gray-500">{{ __('Optional. Inclusive. Leave blank for no end date.') }}</p>
                        <p v-if="errorFor('valid_until')" class="mt-1 text-xs text-red-600" v-text="errorFor('valid_until')" />
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium" for="mr-requester">{{ __('Requester') }}</label>
                    <input id="mr-requester" v-model="form.requester" type="text" :class="inputClass" autocomplete="off" />
                    <p class="mt-1 text-xs text-gray-500">{{ __('Optional. Who requested this redirect.') }}</p>
                    <p v-if="errorFor('requester')" class="mt-1 text-xs text-red-600" v-text="errorFor('requester')" />
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium" for="mr-notes">{{ __('Notes') }}</label>
                    <textarea id="mr-notes" v-model="form.notes" rows="3" :class="inputClass" />
                    <p v-if="errorFor('notes')" class="mt-1 text-xs text-red-600" v-text="errorFor('notes')" />
                </div>

                <div class="flex items-center justify-end gap-3">
                    <Button type="submit" variant="primary" :disabled="saving">
                        {{ saving ? __('Creating…') : __('Create Redirect') }}
                    </Button>
                </div>
            </form>
        </Panel>
    </div>
</template>
