const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const { WebpackManifestPlugin } = require('webpack-manifest-plugin');

module.exports = {
    mode: 'production',
    entry: {
        'js/exam_results_autosave': './assets/js/exam_results_autosave.js',
        'js/exam_dashboard': './assets/js/exam_dashboard.js',
        'js/admin_participants': './assets/js/admin_participants.js',
        'css/sportabzeichen_results': './assets/css/results.css',
        'css/dashboard_css': './assets/css/dashboard.css',
        'css/print_groupcard':'./assets/css/print_groupcard.css',
        'css/sportabzeichen_my_results':'./assets/css/my_results.css',
        'svg/dsa_groupcard':'./assets/svg/dsa_groupcard.svg'
    },
    output: {
        path: path.resolve(__dirname, 'public'),
        filename: '[name].[contenthash:8].js',
        clean: true,
        // HIER KOMMT DIE MAGIE FÜR ASSETS (Bilder/SVG):
        assetModuleFilename: 'img/[name].[contenthash:8][ext]'
    },
    plugins: [
        new WebpackManifestPlugin({
            fileName: 'manifest.json',
            publicPath: 'assets/pulsr-sportabzeichen/'
        }),
        new MiniCssExtractPlugin({
            filename: '[name].[contenthash:8].css'
        })
    ],
    module: {
        rules: [
            {
                test: /\.js$/,
                exclude: /node_modules/,
                use: {
                    loader: 'babel-loader',
                    options: { presets: ['@babel/preset-env'] }
                }
            },
            {
                test: /\.css$/,
                use: [MiniCssExtractPlugin.loader, 'css-loader']
            },
            // --- NEUE RULE FÜR SVG ---
            {
                test: /\.svg$/,
                type: 'asset/resource', // Kopiert die Datei und hasht sie
                generator: {
                    // Expliziter Output-Pfad für SVGs
                    filename: 'img/[name].[contenthash:8][ext]'
                }
            }
        ]
    }
};