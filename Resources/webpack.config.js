const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
// Hier die Änderung: Wir holen das Plugin anders, um den ESM-Fehler zu vermeiden
const { WebpackManifestPlugin } = require('webpack-manifest-plugin');

module.exports = {
    mode: 'production',
    entry: {
        'js/exam_results_autosave': './assets/js/exam_results_autosave.js'
    },
    output: {
        path: path.resolve(__dirname, 'public'), // Zuerst lokal ins Modul bauen
        filename: '[name].[contenthash:8].js',
        clean: true
    },
    plugins: [
        new WebpackManifestPlugin({
            fileName: 'manifest.json',
            // Wichtig für Symfony: Der Pfad in der manifest.json muss relativ zum Asset-Root sein
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
            }
        ]
    }
};