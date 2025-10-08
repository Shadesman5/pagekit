<div id="products" class="uk-form" v-cloak>
    <product-list></product-list>
</div>

<script>
    // Mount Vue.js Product Management App
    (function() {
        window.$data = <?= json_encode($data) ?>;
    })();
</script>
