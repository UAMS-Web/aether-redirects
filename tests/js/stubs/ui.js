import { h } from 'vue';

// Stand-ins for `@statamic/cms/ui`: each renders its slot and nothing else.
export const Header = { props: ['title', 'icon'], render: () => null };
export const Panel = { render() { return h('div', this.$slots.default?.()); } };
export const Button = {
    props: { type: { type: String, default: 'button' }, variant: String, disabled: Boolean },
    render() { return h('button', { type: this.type, disabled: this.disabled }, this.$slots.default?.()); },
};
