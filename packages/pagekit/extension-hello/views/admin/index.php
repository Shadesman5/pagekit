<?php $view->script('hello-admin', 'hello:app/bundle/admin-index.js', ['vue']) ?>

<div id="hello-admin" v-cloak>

    <div class="uk-margin">
        <h2 class="uk-h3 uk-margin-remove">{{ 'Hello Dashboard' | trans }}</h2>
    </div>

    <div class="uk-card uk-card-default uk-card-body">
        <h3 class="uk-card-title">{{ 'Welcome to Hello Extension' | trans }}</h3>
        <p>{{ 'This is a blueprint extension to demonstrate Pagekit extension development.' | trans }}</p>
        
        <div class="uk-margin">
            <h4>{{ 'Features demonstrated:' | trans }}</h4>
            <ul class="uk-list uk-list-bullet">
                <li>{{ 'Dashboard widget' | trans }}</li>
                <li>{{ 'Settings page' | trans }}</li>
                <li>{{ 'Frontend routes' | trans }}</li>
                <li>{{ 'Vue.js components' | trans }}</li>
                <li>{{ 'Permissions system' | trans }}</li>
            </ul>
        </div>
    </div>

</div>