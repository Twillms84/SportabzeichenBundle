const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const { WebpackManifestPlugin } = require('webpack-manifest-plugin');

module.exports = {
    mode: 'production',
    entry: {
        // Bestehendes Autosave
        'js/exam_results_autosave': './assets/js/exam_results_autosave.js',
        // NEU: Scoring Logik
        'js/exam_results_scoring': './assets/js/exam_results_scoring.js',
        // NEU: CSS für die Medaillenfarben
        'css/sportabzeichen_results': './assets/css/results.css'
    },
    output: {
        path: path.resolve(__dirname, 'public'),
        filename: '[name].[contenthash:8].js',
        clean: true
    },
    plugins: [
        new WebpackManifestPlugin({
            fileName: 'manifest.json',
            publicPath: '' // In IServ Bundles oft leer lassen, da der Asset-Helper den Präfix setzt
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
            // NEU: Regel für CSS-Dateien
            {
                test: /\.css$/,
                use: [MiniCssExtractPlugin.loader, 'css-loader']
            }
        ]
    }
}