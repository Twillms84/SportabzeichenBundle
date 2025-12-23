const path = require('path');
const { WebpackManifestPlugin } = require('webpack-manifest-plugin');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

module.exports = {
    mode: 'production',
    // Hier gibst du deine Quelldatei an
    entry: {
        'js/exam_results_autosave': './assets/js/exam_results_autosave.js'
    },
    output: {
        // Ziel: Der offizielle IServ-Asset-Ordner für dein Modul
        path: path.resolve('/usr/share/iserv/web/public/assets/pulsr-sportabzeichen'),
        // Die URL, unter der die Dateien erreichbar sind
        publicPath: '/assets/pulsr-sportabzeichen/',
        filename: '[name].[contenthash:8].js',
        clean: true
    },
    plugins: [
        new WebpackManifestPlugin({
            fileName: 'manifest.json'
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
            }
        ]
    }
};