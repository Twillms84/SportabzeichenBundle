const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const { WebpackManifestPlugin } = require('webpack-manifest-plugin');

module.exports = {
    mode: 'production',
    entry: {
        'js/exam_results_autosave': './assets/js/exam_results_autosave.js',
        'js/exam_results_scoring': './assets/js/exam_results_scoring.js',
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
            // WICHTIG: Diesen Pfad so lassen, wie er in der funktionierenden Version war!
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