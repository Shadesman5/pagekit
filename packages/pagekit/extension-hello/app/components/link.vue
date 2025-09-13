<template>

    <div class="uk-form-row">
        <label for="form-name" class="uk-form-label">{{ 'Name' | trans }}</label>
        <div class="uk-form-controls">
            <input id="form-name" type="text" class="uk-form-width-large" v-model="name"></input>
        </div>
    </div>

</template>

<script>

const HelloLink = {

    link: {
        label: 'Hello'
    },

    props: ['link'],

    data() {
        return {
            name: ''
        }
    },

    watch: {
        name(name) {
            this.link = '@hello/' + encodeURIComponent(name);
        }
    },
    created() {
    if (this.link && this.link.includes('name=')) {
        const match = /name=([^&]*)/.exec(this.link);
        this.name = match ? decodeURIComponent(match[1]) : '';
    }
}


};


export default HelloLink;

window.Links.default.components['hello-link'] = HelloLink;

</script>
