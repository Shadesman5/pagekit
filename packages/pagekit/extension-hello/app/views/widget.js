import Widget from './widget.vue';

window.Widgets.components['hello-widget'] = {
    section: {
        label: 'Hello Widgets',
        priority: 10
    },
    name: 'hello-widget',
    type: 'hello-widget',
    label: 'Hello Widget',
    component: Widget
};
