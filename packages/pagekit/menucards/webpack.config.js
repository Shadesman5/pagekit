/**
 * Webpack configuration for Menucards extension
 */

module.exports = [

    {
        entry: {
            "products": "./app/views/admin/products",
            "menucards": "./app/views/admin/menucards",
            "menu-edit": "./app/views/admin/menu-edit"
        },
        output: {
            filename: "./app/bundle/[name].js"
        },
        module: {
            rules: [
                {
                    test: /\.vue$/,
                    loader: "vue-loader"
                },
                {
                    test: /\.js$/,
                    loader: "babel-loader",
                    exclude: /node_modules/
                }
            ]
        },
        resolve: {
            alias: {
                'vue$': 'vue/dist/vue.esm.js'
            }
        }
    }

];
