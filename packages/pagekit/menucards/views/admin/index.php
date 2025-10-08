<div id="menus" class="uk-form" v-cloak>
    <menu-list></menu-list>
</div>

<script>
    // Mount Vue.js Menu Management App (with Context-Aware Product Creation)
    (function() {
        window.$data = <?= json_encode($data) ?>;
    })();
</script>
