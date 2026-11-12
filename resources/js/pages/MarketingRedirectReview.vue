<script setup>
import { computed, getCurrentInstance, ref } from 'vue';
import { Head } from '@statamic/cms/inertia';
import { Header, Panel, Button } from '@statamic/cms/ui';

/**
 * Marketing-redirect review queue (UAMS-Web/uams-statamic-archive#1357).
 *
 * Lists the redirect requests awaiting approval and lets an approver promote or
 * reject each one. Approve POSTs to the row's `approve_url`; reject reveals a
 * required-reason field and POSTs to its `reject_url`. A request the current
 * user submitted is not reviewable (`can_review` is false) — the server also
 * forbids it, so this only disables the buttons. A reviewed row leaves the list.
 */
const props = defineProps({
    // Pending requests: [{ id, source, destination, response, requester, notes,
    // valid_from, valid_until, can_review, approve_url, reject_url }]
    redirects: { type: Array, default: () => [] },
});

const instance = getCurrentInstance().proxy;
const __ = (key) => instance.__(key);

// Local working copy so a reviewed row can drop out of the list immediately.
const rows = ref([...props.redirects]);

// Per-row UI state keyed by id: whether the reject form is open, its reason,
// whether a request is in flight, and the row's inline error message.
const rejecting = ref({});
const reason = ref({});
const busy = ref({});
const rowError = ref({});
const flash = ref(null);

const pendingCount = computed(() => rows.value.length);

const inputClass =
    'w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm ' +
    'focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 ' +
    'dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100';

// Remove a reviewed row from the list and clear its transient UI state.
function dropRow(id) {
    rows.value = rows.value.filter((row) => row.id !== id);
    delete rejecting.value[id];
    delete reason.value[id];
    delete rowError.value[id];
}

// Pull the first server message out of an axios error, if any.
function messageFromError(error) {
    if (error.response && error.response.data && error.response.data.message) {
        return error.response.data.message;
    }

    return __('Something went wrong. Please try again.');
}

async function approve(row) {
    busy.value[row.id] = true;
    rowError.value[row.id] = null;

    try {
        const { data } = await instance.$axios.post(row.approve_url, {});

        flash.value = data.message;
        dropRow(row.id);
    } catch (error) {
        rowError.value[row.id] = messageFromError(error);
    } finally {
        busy.value[row.id] = false;
    }
}

// Open the inline reject form for a row.
function startReject(row) {
    rejecting.value[row.id] = true;
    reason.value[row.id] = reason.value[row.id] || '';
    rowError.value[row.id] = null;
}

// Close the inline reject form without submitting.
function cancelReject(id) {
    rejecting.value[id] = false;
    rowError.value[id] = null;
}

async function confirmReject(row) {
    busy.value[row.id] = true;
    rowError.value[row.id] = null;

    try {
        const { data } = await instance.$axios.post(row.reject_url, {
            reason: reason.value[row.id] || '',
        });

        flash.value = data.message;
        dropRow(row.id);
    } catch (error) {
        rowError.value[row.id] = messageFromError(error);
    } finally {
        busy.value[row.id] = false;
    }
}
</script>

<template>
    <Head :title="__('Review Redirects')" />

    <div class="max-w-4xl mx-auto" data-max-width-wrapper>
        <Header :title="__('Review Redirects')" icon="flag" />

        <div
            v-if="flash"
            class="mb-4 rounded-md border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-700 dark:bg-green-900/40 dark:text-green-200"
            v-text="flash"
        />

        <Panel>
            <div class="p-4">
                <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                    {{ pendingCount }}
                    {{ pendingCount === 1 ? __('redirect awaiting approval.') : __('redirects awaiting approval.') }}
                    {{ __('A redirect does not serve until it is approved.') }}
                </p>

                <p v-if="pendingCount === 0" class="rounded-md bg-gray-50 px-4 py-6 text-center text-sm text-gray-500 dark:bg-gray-900/40">
                    {{ __('Nothing to review right now.') }}
                </p>

                <ul v-else class="space-y-4">
                    <li
                        v-for="row in rows"
                        :key="row.id"
                        class="rounded-md border border-gray-200 p-4 dark:border-gray-700"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium">
                                    <code>{{ row.source }}</code>
                                    <span class="text-gray-400">&rarr;</span>
                                    <code>{{ row.destination }}</code>
                                </p>
                                <p class="mt-1 text-xs text-gray-500">
                                    {{ __('Type') }}: {{ row.response }}
                                    <span v-if="row.requester"> · {{ __('Requester') }}: {{ row.requester }}</span>
                                    <span v-if="row.valid_from || row.valid_until">
                                        · {{ __('Window') }}: {{ row.valid_from || '…' }} – {{ row.valid_until || '…' }}
                                    </span>
                                </p>
                                <p v-if="row.notes" class="mt-1 text-xs text-gray-500" v-text="row.notes" />
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <Button
                                    variant="primary"
                                    :disabled="!row.can_review || busy[row.id]"
                                    @click="approve(row)"
                                >
                                    {{ __('Approve') }}
                                </Button>
                                <Button
                                    variant="danger"
                                    :disabled="!row.can_review || busy[row.id]"
                                    @click="startReject(row)"
                                >
                                    {{ __('Reject') }}
                                </Button>
                            </div>
                        </div>

                        <p v-if="!row.can_review" class="mt-2 text-xs text-amber-600 dark:text-amber-400">
                            {{ __('You requested this redirect, so you cannot review it.') }}
                        </p>

                        <div v-if="rejecting[row.id]" class="mt-3 border-t border-gray-200 pt-3 dark:border-gray-700">
                            <label class="mb-1 block text-sm font-medium" :for="`reason-${row.id}`">
                                {{ __('Rejection reason') }}
                            </label>
                            <textarea
                                :id="`reason-${row.id}`"
                                v-model="reason[row.id]"
                                rows="2"
                                :class="inputClass"
                                :placeholder="__('Why is this redirect being rejected?')"
                            />
                            <div class="mt-2 flex items-center justify-end gap-2">
                                <Button variant="ghost" :disabled="busy[row.id]" @click="cancelReject(row.id)">
                                    {{ __('Cancel') }}
                                </Button>
                                <Button variant="danger" :disabled="busy[row.id]" @click="confirmReject(row)">
                                    {{ __('Confirm rejection') }}
                                </Button>
                            </div>
                        </div>

                        <p v-if="rowError[row.id]" class="mt-2 text-xs text-red-600" v-text="rowError[row.id]" />
                    </li>
                </ul>
            </div>
        </Panel>
    </div>
</template>
