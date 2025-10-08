<?php $view->script('product-index', 'menucards:app/bundle/product-index.js', ['vue']) ?>

<div id="products" v-cloak>
    <product-list></product-list>
</div>

<script>
window.$data = <?= json_encode($data) ?>;
</script>
