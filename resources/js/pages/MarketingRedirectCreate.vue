<script setup>
import { computed, getCurrentInstance, ref, watch } from 'vue';
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
 *
 * Choosing a domain makes the request a rule on that redirect domain instead
 * (UAMS-Web/aether-redirects#83). The screen proposes the domain a destination
 * calls for, the way the server checks it: a `uams.edu` page goes on `uams.info`
 * and a `uamshealth.com` page on `uams.health`.
 *
 * On a vanity domain the source may be left blank, and the server generates a
 * 7-character back-half for it (UAMS-Web/aether-redirects#15).
 *
 * Every request answers whether it involves Creative Services, and a "yes" asks for
 * the job number (UAMS-Web/aether-redirects#25). The answer is made on purpose, with
 * no default, and the server refuses a vanity domain request that answers no.
 */
const props = defineProps({
    // CP endpoint that validates + persists the redirect
    storeUrl: { type: String, required: true },
    // Selectable HTTP redirect types ([{ value, label }])
    responseOptions: { type: Array, default: () => [] },
    // Domains a request can be made for, vanity domains first
    hostOptions: { type: Array, default: () => [] },
    // Whether the current user can approve, and so can save a domain that does not suit the destination
    canApprove: { type: Boolean, default: false },
    // Domains where a blank source asks the server to generate a back-half
    generatedHosts: { type: Array, default: () => [] },
    // The job number format in words, from the add-on's `creative_services` setting
    jobNumberDescription: { type: String, default: '' },
});

const instance = getCurrentInstance().proxy;
const __ = (key) => instance.__(key);

// A blank submission, reused to reset the form after a successful create.
const blankForm = () => ({
    host: '',
    source: '',
    destination: '',
    response: '301',
    valid_from: '',
    valid_until: '',
    requester: '',
    notes: '',
    creative_services: '',
    job_number: '',
});

const form = ref(blankForm());
const errors = ref({});
const saving = ref(false);
const successMessage = ref(null);

// The domain a destination URL calls for, or '' when it calls for none.
function proposedHost(destination) {
    let hostname;

    try {
        hostname = new URL(destination.trim()).hostname.toLowerCase();
    } catch {
        return '';
    }

    const within = (domain) => hostname === domain || hostname.endsWith('.' + domain);

    if (within('uams.edu')) {
        return 'uams.info';
    }

    return within('uamshealth.com') ? 'uams.health' : '';
}

// Whether the person has picked a domain themselves, which a later destination edit leaves alone.
const hostTouched = ref(false);

watch(
    () => form.value.destination,
    (destination) => {
        if (!hostTouched.value) {
            form.value.host = proposedHost(destination);
        }
    },
);

// Whether the chosen domain generates a back-half when the source is left blank.
const generatesBackHalf = computed(() => props.generatedHosts.includes(form.value.host));

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
        hostTouched.value = false;
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
                    {{ __('Create a redirect from a short/vanity path to a destination. It serves on the chosen domain, or on a 404 for every site when no domain is chosen.') }}
                </p>

                <div
                    v-if="successMessage"
                    class="rounded-md border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-700 dark:bg-green-900/40 dark:text-green-200"
                    v-text="successMessage"
                />

                <div>
                    <label class="mb-1 block text-sm font-medium" for="mr-host">{{ __('Domain') }}</label>
                    <select id="mr-host" v-model="form.host" :class="inputClass" @change="hostTouched = true">
                        <option value="">{{ __('Main site (404 redirect)') }}</option>
                        <option v-for="host in hostOptions" :key="host" :value="host">{{ host }}</option>
                    </select>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ __('Proposed from the destination: uams.info for a uams.edu page, uams.health for a uamshealth.com page. Choose uamsbaptist.health for a UAMS Health and Baptist Health partnership page.') }}
                        <span v-if="canApprove">{{ __('As an approver you can save a domain that does not suit the destination.') }}</span>
                    </p>
                    <p v-if="errorFor('host')" class="mt-1 text-xs text-red-600" v-text="errorFor('host')" />
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium" for="mr-source">{{ __('Source path') }}</label>
                    <input
                        id="mr-source"
                        v-model="form.source"
                        type="text"
                        :class="inputClass"
                        :placeholder="generatesBackHalf ? __('Leave blank to generate one') : '/flu-shot'"
                        autocomplete="off"
                    />
                    <p class="mt-1 text-xs text-gray-500">
                        {{ __('The path to redirect from, relative to the site root.') }}
                        <span v-if="generatesBackHalf">{{ __('Leave it blank to get a generated 7-character back-half, shown when the request is submitted.') }}</span>
                    </p>
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

                <fieldset>
                    <legend class="mb-1 block text-sm font-medium">{{ __('Does this request involve Creative Services?') }}</legend>
                    <label class="mr-4 inline-flex items-center gap-2 text-sm">
                        <input v-model="form.creative_services" type="radio" name="creative_services" value="yes" />
                        {{ __('Yes') }}
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input v-model="form.creative_services" type="radio" name="creative_services" value="no" />
                        {{ __('No') }}
                    </label>
                    <p v-if="errorFor('creative_services')" class="mt-1 text-xs text-red-600" v-text="errorFor('creative_services')" />
                </fieldset>

                <div v-if="form.creative_services === 'yes'">
                    <label class="mb-1 block text-sm font-medium" for="mr-job-number">{{ __('Creative Services job number') }}</label>
                    <input id="mr-job-number" v-model="form.job_number" type="text" :class="inputClass" autocomplete="off" />
                    <p class="mt-1 text-xs text-gray-500">
                        {{ __('Required. The approver confirms the number before approving.') }}
                        <span v-if="jobNumberDescription">{{ __('Format:') }} {{ jobNumberDescription }}.</span>
                    </p>
                    <p v-if="errorFor('job_number')" class="mt-1 text-xs text-red-600" v-text="errorFor('job_number')" />
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
