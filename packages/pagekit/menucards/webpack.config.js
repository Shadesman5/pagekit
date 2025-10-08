module.exports = [
    {
        entry: {
            "settings": "./app/components/settings.js",
            "link-menucards": "./app/components/link-menucards.js",
            "product-index": "./app/components/product-index.js",
            "menu-index": "./app/components/menu-index.js"
        },
        output: { filename: "./app/bundle/[name].js" },
        module: {
            rules: [
                { test: /\.vue$/, use: 'vue-loader' }
            ]
        }
    }
];
