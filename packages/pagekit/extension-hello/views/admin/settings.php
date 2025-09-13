<?php $view->script('settings', 'hello:app/bundle/settings.js', ['vue']) ?>

<div id="settings" class="uk-form uk-form-stacked" v-cloak>

    <div class="uk-flex uk-flex-between uk-flex-wrap uk-margin">
        <h2 class="uk-margin-remove">{{ 'Edit Settings' | trans }}</h2>
        <button class="uk-button uk-button-primary" @click.prevent="save">{{ 'Save' | trans }}</button>
    </div>

    <div class="uk-margin">
        <label class="uk-form-label">{{ 'Default name' | trans }}</label>
        <div class="uk-form-controls">
            <input class="uk-input" type="text" v-model="config.default">
        </div>
    </div>

</div>
