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
 *
 * A tagged URL request walks the site's cascade (UAMS-Web/aether-redirects#120): the channel
 * narrows the source, each further choice narrows the next step, and a step with no list of
 * its own offers the generic one. The options come from the cascade endpoint, fetched again on
 * every choice, and a choice the new options no longer offer is cleared. A site with no lookup
 * data gets the three plain fields it always had.
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
    // Whether the current user can ask for the main site; the custom level types a back-half on a vanity domain only
    canUseMainSite: { type: Boolean, default: true },
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
    // CP endpoint that answers the options each cascade step offers for the choices so far
    cascadeUrl: { type: String, default: '' },
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
    qr: false,
    channel: '',
    steps: {},
    lists: {},
    custom_values: {},
    fields: {},
});

// UAMS guidance on QR codes, which the two notices link to.
const qrGuidanceUrl = 'https://communications.uams.edu/web/kb/qr-codes/';

// The fields each kind of request sends; the server refuses the vanity fields on a tagged-only request.
const fieldsByKind = {
    redirect: ['host', 'short_link', 'source', 'destination', 'response', 'valid_from', 'valid_until', 'creative_services', 'job_number', 'requester', 'notes', 'qr'],
    tagged: ['kind', 'destination', 'suppress_utm', 'creative_services', 'job_number', 'requester', 'notes'],
};

// The three fields a site with no lookup data is asked for.
const legacyFields = ['utm_campaign', 'utm_id', 'utm_marketing_tactic'];

// Whether the form asks for a tagged URL alone.
const taggedOnly = computed(() => form.value.kind === 'tagged');

// The submission for the chosen kind of request.
function payload() {
    const body = Object.fromEntries(fieldsByKind[form.value.kind].map((field) => [field, form.value[field]]));

    if (form.value.kind === 'tagged') {
        Object.assign(body, taggedChoices());
    }

    return body;
}

// A map without its blank values.
function filled(map) {
    return Object.fromEntries(Object.entries(map).filter(([, value]) => typeof value === 'string' && value.trim() !== ''));
}

// The cascade choices and request fields of a tagged request, blanks left out.
function taggedChoices() {
    const choices = {};

    if (form.value.channel !== '') {
        choices.channel = form.value.channel;
    }

    for (const key of ['steps', 'lists', 'custom_values']) {
        const map = filled(form.value[key]);

        if (Object.keys(map).length) {
            choices[key] = map;
        }
    }

    const fields = hasCascade.value ? filled(form.value.fields) : filled(Object.fromEntries(legacyFields.map((field) => [field, form.value[field]])));

    return { ...choices, ...fields };
}

const form = ref(blankForm());

// What the cascade endpoint last offered: the channels, a step's options, a list's options, and the request fields.
const cascade = ref({ channel: [], steps: [], lists: [], fields: [] });
const cascadeLoaded = ref(false);

// Whether the site has anything to choose from, so the plain fields are not needed.
const hasCascade = computed(() => cascade.value.channel.length > 0 || cascade.value.steps.length > 0 || cascade.value.lists.length > 0 || cascade.value.fields.length > 0);

// The request fields shown: the site's handles, or the plain three when it has no lookup data.
const requestFields = computed(() => (hasCascade.value ? cascade.value.fields : legacyFields));

// The id of the latest fetch, so a slow answer to an earlier choice is dropped.
let cascadeRequest = 0;

// Whether a choice is one of the options offered.
const offers = (options, id) => options.some((option) => option.id === id);

// Keep the choices the options still offer, and the custom values beside them.
function keepChoices(entries, key, chosen, custom) {
    const kept = {};

    for (const entry of entries) {
        const id = entry[key];
        const choice = chosen[id];

        if (typeof choice === 'string' && choice !== '' && offers(entry.options, choice)) {
            kept[id] = choice;
        }

        if (typeof form.value.custom_values[id] === 'string' && form.value.custom_values[id] !== '') {
            custom[id] = form.value.custom_values[id];
        }
    }

    return kept;
}

// Fetch the options for the choices so far, then drop each choice they no longer offer.
async function loadCascade() {
    if (!props.cascadeUrl || !props.taggedAvailable) {
        return;
    }

    const request = ++cascadeRequest;

    try {
        const { data } = await instance.$axios.get(props.cascadeUrl, {
            params: { channel: form.value.channel, steps: filled(form.value.steps), lists: filled(form.value.lists) },
        });

        if (request !== cascadeRequest) {
            return;
        }

        cascade.value = { channel: data.channel || [], steps: data.steps || [], lists: data.lists || [], fields: data.fields || [] };
        cascadeLoaded.value = true;

        const custom = {};
        const steps = keepChoices(cascade.value.steps, 'step', form.value.steps, custom);
        const lists = keepChoices(cascade.value.lists, 'list', form.value.lists, custom);
        if (form.value.channel !== '' && !offers(cascade.value.channel, form.value.channel)) {
            form.value.channel = '';
        }

        form.value.steps = steps;
        form.value.lists = lists;
        form.value.custom_values = custom;

        // A cleared choice changes what follows it, and the watcher above asks again
    } catch {
        if (request === cascadeRequest) {
            cascadeLoaded.value = true;
        }
    }
}

// A choice at the channel, a step or a list narrows what follows.
watch(
    () => [form.value.channel, JSON.stringify(form.value.steps), JSON.stringify(form.value.lists)],
    () => {
        if (form.value.kind === 'tagged') {
            loadCascade();
        }
    },
);

// Choosing a tagged request asks for the cascade, and does so once.
watch(
    () => form.value.kind,
    (kind) => {
        if (kind === 'tagged' && !cascadeLoaded.value) {
            loadCascade();
        }
    },
    { immediate: true },
);

// The label a step or list is shown under.
const labelFor = (key) => String(key).replace(/[_-]+/g, ' ');

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

// Whether the form offers a QR code: a vanity URL has one to make, a request on any other domain does not.
const offersQr = computed(() => !taggedOnly.value && generatesBackHalf.value);

// A code is made for a vanity URL, so leaving the vanity domains drops the request for one.
watch(offersQr, (offered) => {
    if (!offered) {
        form.value.qr = false;
    }
});

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
        successMessage.value = data.qr ? data.message + ' ' + __('Its QR code redirect is saved with it, and both go live together.') : data.message;
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
                        <option v-if="!shortLinksOnly && canUseMainSite" value="">{{ __('Main site (404 redirect)') }}</option>
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
                    <div v-if="cascade.channel.length">
                        <label class="mb-1 block text-sm font-medium" for="mr-channel">{{ __('Channel') }}</label>
                        <select id="mr-channel" v-model="form.channel" :class="inputClass">
                            <option value="">{{ __('Choose a channel') }}</option>
                            <option v-for="option in cascade.channel" :key="option.id" :value="option.id">{{ option.label }}</option>
                        </select>
                        <p v-if="errorFor('channel')" class="mt-1 text-xs text-red-600" v-text="errorFor('channel')" />
                    </div>

                    <div v-for="entry in cascade.steps" :key="entry.step" :data-step="entry.step">
                        <label class="mb-1 block text-sm font-medium" :for="`mr-step-${entry.step}`">{{ labelFor(entry.step) }}</label>
                        <div class="flex gap-2">
                            <select :id="`mr-step-${entry.step}`" v-model="form.steps[entry.step]" :class="inputClass">
                                <option value="">{{ __('Choose an option') }}</option>
                                <option v-for="option in entry.options" :key="option.id" :value="option.id">{{ option.label }}</option>
                            </select>
                            <input v-model="form.custom_values[entry.step]" type="text" :class="inputClass" :placeholder="__('Or type a value')" :aria-label="labelFor(entry.step)" autocomplete="off" />
                        </div>
                        <p v-if="errorFor(`steps.${entry.step}`)" class="mt-1 text-xs text-red-600" v-text="errorFor(`steps.${entry.step}`)" />
                        <p v-if="errorFor(`custom_values.${entry.step}`)" class="mt-1 text-xs text-red-600" v-text="errorFor(`custom_values.${entry.step}`)" />
                    </div>

                    <div v-for="entry in cascade.lists" :key="entry.list" :data-list="entry.list">
                        <label class="mb-1 block text-sm font-medium" :for="`mr-list-${entry.list}`">{{ labelFor(entry.parameter) }}</label>
                        <div class="flex gap-2">
                            <select :id="`mr-list-${entry.list}`" v-model="form.lists[entry.list]" :class="inputClass">
                                <option value="">{{ __('Choose an option') }}</option>
                                <option v-for="option in entry.options" :key="option.id" :value="option.id">{{ option.label }}</option>
                            </select>
                            <input v-model="form.custom_values[entry.list]" type="text" :class="inputClass" :placeholder="__('Or type a value')" :aria-label="labelFor(entry.parameter)" autocomplete="off" />
                        </div>
                        <p v-if="errorFor(`lists.${entry.list}`)" class="mt-1 text-xs text-red-600" v-text="errorFor(`lists.${entry.list}`)" />
                        <p v-if="errorFor(`custom_values.${entry.list}`)" class="mt-1 text-xs text-red-600" v-text="errorFor(`custom_values.${entry.list}`)" />
                    </div>

                    <div v-for="field in requestFields" :key="field">
                        <label class="mb-1 block text-sm font-medium" :for="`mr-${field}`"><code>{{ field }}</code></label>
                        <input v-if="hasCascade" :id="`mr-${field}`" v-model="form.fields[field]" type="text" :class="inputClass" autocomplete="off" />
                        <input v-else :id="`mr-${field}`" v-model="form[field]" type="text" :class="inputClass" autocomplete="off" />
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

                <div v-if="offersQr" class="rounded-md border border-gray-200 p-3 dark:border-gray-700">
                    <label class="flex items-center gap-2 text-sm font-medium">
                        <input v-model="form.qr" type="checkbox" name="qr" />
                        {{ __('Also make a QR code') }}
                    </label>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ __('A second redirect, the vanity URL with -QR added, is saved with this one. The code encodes that URL, so scans are counted apart from typed visits.') }}
                    </p>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-gray-600 dark:text-gray-400">
                        <li>
                            {{ __('A QR code must always be printed with the visible text URL beside it.') }}
                            <a :href="qrGuidanceUrl" target="_blank" rel="noopener" class="underline">{{ __('QR code guidance') }}</a>
                        </li>
                        <li>
                            {{ __('Do not make your own QR code from the visible text URL, because scans would count as typed visits. Making your own from the QR URL is fine.') }}
                            <a :href="qrGuidanceUrl" target="_blank" rel="noopener" class="underline">{{ __('QR code guidance') }}</a>
                        </li>
                    </ul>
                    <p v-if="errorFor('qr')" class="mt-1 text-xs text-red-600" v-text="errorFor('qr')" />
                </div>

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
