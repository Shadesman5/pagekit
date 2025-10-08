<?php $view->script('product-index', 'menucards:app/bundle/product-index.js', ['vue']) ?>

<div id="products" v-cloak>
    <product-list></product-list>
</div>
