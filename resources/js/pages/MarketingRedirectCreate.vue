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
 * A user who can make short links gets a "Generate code" button on go.uams.edu,
 * which fills the source with a 6-character code and can be pressed again for a
 * new one (UAMS-Web/aether-redirects#84). Typing a source by hand makes the
 * request an ordinary redirect again; a user who can only make short links
 * cannot type one.
 *
 * "Tagged URL only" asks for a tagged URL with no vanity URL
 * (UAMS-Web/aether-redirects#18): the vanity fields are hidden and not sent, and
 * the tagged URL is issued once an approver approves the request.
 *
 * Every request answers whether it involves Creative Services, and a "yes" asks for
 * the job number (UAMS-Web/aether-redirects#25). The answer is made on purpose, with
 * no default, and the server refuses a vanity domain request that answers no.
 */
const props = defineProps({
    // CP endpoint that validates + persists the redirect
    storeUrl: { type: String, required: true },
    // CP endpoint that draws a short-link code
    shortCodeUrl: { type: String, default: '' },
    // Whether the current user can request a vanity URL, not only a tagged URL or short links
    canRequestVanity: { type: Boolean, default: true },
    // Whether the current user can type a back-half; the generated level leaves the source blank
    canTypeBackHalf: { type: Boolean, default: true },
    // The short-link domain, when the current user can make short links there, else null
    shortLinkHost: { type: String, default: null },
    // Selectable HTTP redirect types ([{ value, label }])
    responseOptions: { type: Array, default: () => [] },
    // Domains a request can be made for, vanity domains first
    hostOptions: { type: Array, default: () => [] },
    // Whether the current user can approve, and so can save a domain that does not suit the destination
    canApprove: { type: Boolean, default: false },
    // Domains where a blank source asks the server to generate a back-half
    generatedHosts: { type: Array, default: () => [] },
    // Whether the site keeps tagged-only requests, and so offers them
    taggedAvailable: { type: Boolean, default: false },
    // The job number format in words, from the add-on's `creative_services` setting
    jobNumberDescription: { type: String, default: '' },
});

const instance = getCurrentInstance().proxy;
const __ = (key) => instance.__(key);

// Whether the user can make nothing but short links, so every request is one.
const shortLinksOnly = computed(() => !props.canRequestVanity && props.shortLinkHost !== null);

// Whether the user may request nothing but tagged URLs, so every request is one.
const taggedUserOnly = computed(() => !props.canRequestVanity && props.shortLinkHost === null);

// A blank submission, reused to reset the form after a successful create.
const blankForm = () => ({
    kind: taggedUserOnly.value ? 'tagged' : 'redirect',
    host: shortLinksOnly.value ? props.shortLinkHost : '',
    short_link: shortLinksOnly.value,
    source: '',
    destination: '',
    response: '301',
    valid_from: '',
    valid_until: '',
    requester: '',
    notes: '',
    utm_campaign: '',
    utm_id: '',
    utm_marketing_tactic: '',
    suppress_utm: false,
    creative_services: '',
    job_number: '',
});

// The fields each kind of request sends; the server refuses the vanity fields on a tagged-only request.
const fieldsByKind = {
    redirect: ['host', 'short_link', 'source', 'destination', 'response', 'valid_from', 'valid_until', 'creative_services', 'job_number', 'requester', 'notes'],
    tagged: ['kind', 'destination', 'utm_campaign', 'utm_id', 'utm_marketing_tactic', 'suppress_utm', 'creative_services', 'job_number', 'requester', 'notes'],
};

// Whether the form asks for a tagged URL alone.
const taggedOnly = computed(() => form.value.kind === 'tagged');

// The submission for the chosen kind of request.
function payload() {
    return Object.fromEntries(fieldsByKind[form.value.kind].map((field) => [field, form.value[field]]));
}

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
        if (!hostTouched.value && !shortLinksOnly.value) {
            form.value.host = proposedHost(destination);
        }
    },
);

// Whether the chosen domain generates a back-half when the source is left blank.
const generatesBackHalf = computed(() => props.generatedHosts.includes(form.value.host));

// Whether the Generate code button applies to the chosen domain.
const offersShortCode = computed(() => props.shortLinkHost !== null && form.value.host === props.shortLinkHost);

// Leaving the short-link domain makes the request an ordinary redirect again.
watch(
    () => form.value.host,
    (host) => {
        if (host !== props.shortLinkHost) {
            form.value.short_link = false;
        }
    },
);

// A source typed by hand is an ordinary path, unless the user can make only short links.
function sourceTyped() {
    if (!shortLinksOnly.value) {
        form.value.short_link = false;
    }
}

const generating = ref(false);

// Draw a code for the short link; pressing again draws a new one.
async function generateCode() {
    generating.value = true;
    errors.value = {};

    try {
        const { data } = await instance.$axios.post(props.shortCodeUrl);

        form.value.source = data.code;
        form.value.short_link = true;
    } catch (error) {
        errors.value = (error.response && error.response.data.errors) || { source: [__('Something went wrong. Please try again.')] };
    } finally {
        generating.value = false;
    }
}

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
        const { data } = await instance.$axios.post(props.storeUrl, payload());

        // Report success and clear the form for the next redirect.
        successMessage.value = data.message;
        form.value = blankForm();
        hostTouched.value = false;
    } catch (error) {
        // A 422 carries per-field validation / collision messages to show inline.
        if (error.response && error.response.status === 422) {
            errors.value = error.response.data.errors || {};
        } else {
            errors.value = { destination: [__('Something went wrong. Please try again.')] };
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

                <div v-if="taggedAvailable && !taggedUserOnly">
                    <span class="mb-1 block text-sm font-medium">{{ __('Request') }}</span>
                    <div class="flex gap-4 text-sm">
                        <label class="flex items-center gap-2">
                            <input v-model="form.kind" type="radio" name="mr-kind" value="redirect" />
                            {{ __('Vanity URL') }}
                        </label>
                        <label class="flex items-center gap-2">
                            <input v-model="form.kind" type="radio" name="mr-kind" value="tagged" />
                            {{ __('Tagged URL only') }}
                        </label>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">{{ __('A tagged URL only request has no vanity URL. The tagged URL is issued once an approver approves it.') }}</p>
                    <p v-if="errorFor('kind')" class="mt-1 text-xs text-red-600" v-text="errorFor('kind')" />
                </div>

                <div v-if="!taggedOnly">
                    <label class="mb-1 block text-sm font-medium" for="mr-host">{{ __('Domain') }}</label>
                    <select id="mr-host" v-model="form.host" :class="inputClass" :disabled="shortLinksOnly" @change="hostTouched = true">
                        <option v-if="!shortLinksOnly && canTypeBackHalf" value="">{{ __('Main site (404 redirect)') }}</option>
                        <option v-for="host in hostOptions" :key="host" :value="host">{{ host }}</option>
                    </select>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ __('Proposed from the destination: uams.info for a uams.edu page, uams.health for a uamshealth.com page. Choose uamsbaptist.health for a UAMS Health and Baptist Health partnership page.') }}
                        <span v-if="canApprove">{{ __('As an approver you can save a domain that does not suit the destination.') }}</span>
                    </p>
                    <p v-if="errorFor('host')" class="mt-1 text-xs text-red-600" v-text="errorFor('host')" />
                </div>

                <div v-if="!taggedOnly && (canTypeBackHalf || offersShortCode)">
                    <label class="mb-1 block text-sm font-medium" for="mr-source">{{ __('Source path') }}</label>
                    <div class="flex gap-2">
                        <input
                            id="mr-source"
                            v-model="form.source"
                            type="text"
                            :class="inputClass"
                            :readonly="shortLinksOnly"
                            :placeholder="generatesBackHalf ? __('Leave blank to generate one') : '/flu-shot'"
                            autocomplete="off"
                            @input="sourceTyped"
                        />
                        <Button v-if="offersShortCode" type="button" :disabled="generating" @click="generateCode">
                            {{ form.short_link ? __('Generate another') : __('Generate code') }}
                        </Button>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ __('The path to redirect from, relative to the site root.') }}
                        <span v-if="generatesBackHalf">{{ __('Leave it blank to get a generated 7-character back-half, shown when the request is submitted.') }}</span>
                        <span v-if="offersShortCode">{{ __('Generate code makes a short link with a 6-character code. It goes live at once when the destination is on a UAMS domain, and waits for an approver otherwise.') }}</span>
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

                <template v-if="taggedOnly">
                    <div v-for="field in ['utm_campaign', 'utm_id', 'utm_marketing_tactic']" :key="field">
                        <label class="mb-1 block text-sm font-medium" :for="`mr-${field}`"><code>{{ field }}</code></label>
                        <input :id="`mr-${field}`" v-model="form[field]" type="text" :class="inputClass" autocomplete="off" />
                        <p v-if="errorFor(field)" class="mt-1 text-xs text-red-600" v-text="errorFor(field)" />
                    </div>

                    <div>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.suppress_utm" type="checkbox" />
                            {{ __('Leave the utm_ parameters off the URL') }}
                        </label>
                        <p v-if="errorFor('suppress_utm')" class="mt-1 text-xs text-red-600" v-text="errorFor('suppress_utm')" />
                    </div>
                </template>

                <div v-if="!taggedOnly">
                    <label class="mb-1 block text-sm font-medium" for="mr-response">{{ __('Redirect type') }}</label>
                    <select id="mr-response" v-model="form.response" :class="inputClass">
                        <option v-for="option in responseOptions" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                    <p v-if="errorFor('response')" class="mt-1 text-xs text-red-600" v-text="errorFor('response')" />
                </div>

                <div v-if="!taggedOnly" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
                        {{ saving ? __('Creating…') : taggedOnly ? __('Request Tagged URL') : __('Create Redirect') }}
                    </Button>
                </div>
            </form>
        </Panel>
    </div>
</template>
