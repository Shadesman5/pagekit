<template>
    <div class="uk-card uk-card-default uk-card-body">
        <h3 class="uk-card-title">{{ config.greeting || 'Hello Widget' }}</h3>
        <p>This is a dynamic widget component.</p>
    </div>
</template>

<script>
const HelloWidget = {
    name: 'hello-widget',
    props: ['widget', 'config'],
    mounted() {
        console.log('Widget mounted', this.widget, this.config)
    }
}

export default HelloWidget;

window.Widgets.components.hello = HelloWidget;
</script>
