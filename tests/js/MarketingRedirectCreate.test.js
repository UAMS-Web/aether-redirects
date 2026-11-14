// @vitest-environment jsdom
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Create from '../../resources/js/pages/MarketingRedirectCreate.vue';

// The cascade endpoint's answer (UAMS-Web/aether-redirects#118) for a site with lookup data.
const cascade = {
    channel: [{ id: 'chan-social', label: 'Social' }],
    steps: [
        { step: 'source', options: [{ id: 'opt-facebook', label: 'Facebook' }] },
        { step: 'medium', options: [{ id: 'opt-paid', label: 'Paid social' }] },
    ],
    lists: [{ parameter: 'audience', list: 'list-audience', options: [{ id: 'opt-patients', label: 'Patients' }] }],
    fields: ['utm_campaign', 'utm_id'],
};

// A site with no lookup data answers an empty cascade.
const empty = { channel: [], steps: [], lists: [], fields: [] };

// Mount the create page for a user who may request nothing but tagged URLs, with the endpoints faked.
async function mountPage(answer) {
    // A page that asks again for every answer would never settle; refuse after a few calls so
    // the test fails on the count instead of hanging.
    const get = vi.fn(async () => {
        if (get.mock.calls.length > 10) {
            throw new Error('The cascade was requested over and over.');
        }

        return { data: answer };
    });
    const post = vi.fn().mockResolvedValue({ data: { message: 'Saved.' } });
    const wrapper = mount(Create, {
        props: { storeUrl: '/store', cascadeUrl: '/cascade', taggedAvailable: true, canRequestVanity: false },
        global: { config: { globalProperties: { __: (key) => key, $axios: { get, post } } } },
    });

    await flushPromises();

    return { wrapper, get, post };
}

const fill = async (wrapper, selector, value) => {
    await wrapper.find(selector).setValue(value);
    await flushPromises();
};

describe('the tagged request payload', () => {
    it('keys each choice the way the validator reads it and leaves blanks out', async () => {
        const { wrapper, post } = await mountPage(cascade);

        await fill(wrapper, '#mr-destination', 'https://www.uams.edu/clinics/flu');
        await fill(wrapper, '#mr-channel', 'chan-social');
        await fill(wrapper, '#mr-step-source', 'opt-facebook');
        await fill(wrapper, '[data-step="source"] input[type="text"]', 'Spring Push');
        await fill(wrapper, '#mr-list-list-audience', 'opt-patients');
        await fill(wrapper, '#mr-utm_campaign', 'flu-2026');
        // The medium step, the utm_id field and the list's custom value stay blank
        await fill(wrapper, '#mr-requester', 'pat@example.test');
        await wrapper.find('input[name="creative_services"][value="no"]').setValue(true);
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(1);
        expect(post).toHaveBeenCalledWith('/store', {
            kind: 'tagged',
            destination: 'https://www.uams.edu/clinics/flu',
            suppress_utm: false,
            creative_services: 'no',
            job_number: '',
            requester: 'pat@example.test',
            notes: '',
            channel: 'chan-social',
            steps: { source: 'opt-facebook' },
            lists: { 'list-audience': 'opt-patients' },
            custom_values: { source: 'Spring Push' },
            utm_campaign: 'flu-2026',
        });
    });

    it('sends the vanity fields of no kind and omits every choice map when nothing is chosen', async () => {
        const { wrapper, post } = await mountPage(cascade);

        await fill(wrapper, '#mr-destination', 'https://www.uams.edu/clinics/flu');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        const body = post.mock.calls[0][1];

        expect(body).not.toHaveProperty('channel');
        expect(body).not.toHaveProperty('steps');
        expect(body).not.toHaveProperty('lists');
        expect(body).not.toHaveProperty('custom_values');
        expect(body).not.toHaveProperty('host');
        expect(body).not.toHaveProperty('source');
        expect(body).not.toHaveProperty('utm_campaign');
    });

    it('drops a choice the narrowed options no longer offer', async () => {
        const { wrapper, get } = await mountPage(cascade);

        await fill(wrapper, '#mr-channel', 'chan-social');
        await fill(wrapper, '#mr-step-source', 'opt-facebook');

        // The next answer offers a different source, so the earlier pick is cleared
        get.mockResolvedValue({ data: { ...cascade, steps: [{ step: 'source', options: [{ id: 'opt-x', label: 'X' }] }] } });
        await fill(wrapper, '#mr-channel', '');
        await wrapper.find('#mr-channel').setValue('chan-social');
        await flushPromises();

        expect(wrapper.find('#mr-step-source').element.value).toBe('');
    });
});

describe('the cascade requests', () => {
    it('asks once on load and once more for a choice, not again for an answer that clears nothing', async () => {
        const { wrapper, get } = await mountPage(cascade);

        expect(get).toHaveBeenCalledTimes(1);

        await fill(wrapper, '#mr-channel', 'chan-social');

        expect(get).toHaveBeenCalledTimes(2);
        expect(get).toHaveBeenLastCalledWith('/cascade', { params: { channel: 'chan-social', steps: {}, lists: {} } });
    });
});

describe('a site with no lookup data', () => {
    it('shows the three plain fields and no cascade inputs', async () => {
        const { wrapper } = await mountPage(empty);

        expect(wrapper.find('#mr-channel').exists()).toBe(false);
        expect(wrapper.find('[data-step]').exists()).toBe(false);
        expect(wrapper.find('[data-list]').exists()).toBe(false);
        expect(['utm_campaign', 'utm_id', 'utm_marketing_tactic'].map((field) => wrapper.find(`#mr-${field}`).exists())).toEqual([true, true, true]);
    });

    it('submits the plain fields as top-level values', async () => {
        const { wrapper, post } = await mountPage(empty);

        await fill(wrapper, '#mr-destination', 'https://www.uams.edu/clinics/flu');
        await fill(wrapper, '#mr-utm_campaign', 'flu-2026');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        const body = post.mock.calls[0][1];

        expect(body.utm_campaign).toBe('flu-2026');
        expect(body).not.toHaveProperty('utm_id');
        expect(body).not.toHaveProperty('channel');
    });
});

// Mount the create page for a user who may request a vanity URL, which is the form's first kind.
async function mountVanity(answer) {
    const get = vi.fn(async () => ({ data: answer }));
    const post = vi.fn().mockResolvedValue({ data: { message: 'Saved.' } });
    const wrapper = mount(Create, {
        props: {
            storeUrl: '/store',
            cascadeUrl: '/cascade',
            taggedAvailable: true,
            canRequestVanity: true,
            hostOptions: ['uams.info'],
            responseOptions: [{ value: '301', label: '301' }],
        },
        global: { config: { globalProperties: { __: (key) => key, $axios: { get, post } } } },
    });

    await flushPromises();

    return { wrapper, get, post };
}

describe('a vanity request (UAMS-Web/aether-redirects#125)', () => {
    it('shows the cascade and sends its choices with the vanity fields', async () => {
        const { wrapper, post } = await mountVanity(cascade);

        expect(wrapper.find('#mr-channel').exists()).toBe(true);

        await fill(wrapper, '#mr-destination', 'https://www.uams.edu/clinics/flu');
        await fill(wrapper, '#mr-channel', 'chan-social');
        await fill(wrapper, '#mr-step-source', 'opt-facebook');
        await fill(wrapper, '#mr-utm_campaign', 'flu-2026');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        const body = post.mock.calls[0][1];

        expect(body).toMatchObject({
            destination: 'https://www.uams.edu/clinics/flu',
            channel: 'chan-social',
            steps: { source: 'opt-facebook' },
            utm_campaign: 'flu-2026',
            suppress_utm: false,
        });
        expect(body).toHaveProperty('response');
        expect(body).not.toHaveProperty('kind');
    });

    it('is unchanged for a site with no lookup data', async () => {
        const { wrapper, post } = await mountVanity(empty);

        expect(wrapper.find('#mr-channel').exists()).toBe(false);
        expect(wrapper.find('#mr-utm_campaign').exists()).toBe(false);

        await fill(wrapper, '#mr-destination', 'https://www.uams.edu/clinics/flu');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        const body = post.mock.calls[0][1];

        expect(body).not.toHaveProperty('suppress_utm');
        expect(body).not.toHaveProperty('channel');
        expect(body).not.toHaveProperty('utm_campaign');
    });

    it('shows a refusal against the choice it names', async () => {
        const { wrapper, post } = await mountVanity(cascade);

        post.mockRejectedValue({ response: { status: 422, data: { errors: { 'steps.source': ['Pick a listed option.'] } } } });
        await fill(wrapper, '#mr-destination', 'https://www.uams.edu/clinics/flu');
        await fill(wrapper, '#mr-channel', 'chan-social');
        await fill(wrapper, '#mr-step-source', 'opt-facebook');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[data-step="source"]').text()).toContain('Pick a listed option.');
    });
});
