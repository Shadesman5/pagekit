<template>
    <div>
        <div class="uk-card-header pk-panel-teaser">
            <form v-if="editing" class="uk-form-stacked">
                <div class="uk-margin">
                    <label for="form-hello-title" class="uk-form-label">{{ 'Title' | trans }}</label>

                    <div class="uk-form-controls uk-form-controls-text">
                        <input id="form-hello-title" class="uk-width-1-1 uk-input" type="text" name="widget[title]"
                            v-model="widget.title">
                    </div>
                </div>

            </form>

            <div v-else>

                <h3 v-if="widget.title">{{ widget.title }}</h3>

            </div>
        </div>
    </div>

</template>

<script>
const Dashboard = {
    type: {
        id: 'hello',
        label: 'Hello Dashboard',
        defaults: {
            title: 'My Hello Widget'
        }
    },

    props: ['value', 'editing'],
    data() {
        return {
            widget: this.value
        };
    }
};

export default Dashboard;

window.Dashboard.components.hello = Dashboard;
</script>