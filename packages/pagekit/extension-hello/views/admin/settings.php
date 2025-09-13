<?php $view->script('settings', 'hello:app/bundle/settings.js', ['vue']) ?>

<div id="settings" class="uk-form-horizontal" v-cloak>

    <div class="uk-margin">
        <h2 class="uk-h3 uk-margin-remove">{{ 'Edit Settings' | trans }}</h2>
    </div>

    <div class="uk-margin">
        <label class="uk-form-label">{{ 'Default name' | trans }}</label>
        <div class="uk-form-controls">
            <input class="uk-input" type="text" v-model="config.default">
        </div>
    </div>

</div>
