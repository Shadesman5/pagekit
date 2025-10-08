<?php $view->script('menu-index', 'menucards:app/bundle/menu-index.js', ['vue']) ?>

<div id="menus" v-cloak>
    <menu-list></menu-list>
</div>

<script>
window.$data = <?= json_encode($data) ?>;
</script>
