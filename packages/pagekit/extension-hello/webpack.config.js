const { VueLoaderPlugin } = require('vue-loader');
const path = require('path');

module.exports = [

    {
        mode: 'production',
        entry: {
            "settings": "./app/views/settings.js",
            "link": "./app/components/link.vue",
            "dashboard": "./app/components/dashboard.vue",
            "widget": "./app/components/widget.vue"
        },
        output: {
            path: path.resolve(__dirname, './app/bundle'),
            filename: "[name].js"
        },
        module: {
            rules: [
                {
                    test: /\.vue$/,
                    loader: 'vue-loader'
                },
                {
                    test: /\.js$/,
                    loader: 'babel-loader',
                    exclude: /node_modules/
                }
            ]
        },
        resolve: {
            extensions: ['.js', '.vue'],
            alias: {
                vue$: 'vue/dist/vue.esm.js'
            }
        },
        plugins: [
            new VueLoaderPlugin()
        ]
    }

];
