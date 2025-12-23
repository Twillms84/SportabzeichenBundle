const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
// Version 4 unterstützt diesen direkten Require:
const { WebpackManifestPlugin } = require('webpack-manifest-plugin');

module.exports = {
    mode: 'production',
    entry: {
        'js/exam_results_autosave': './assets/js/exam_results_autosave.js'
    },
    output: {
        path: path.resolve(__dirname, 'public'),
        filename: '[name].[contenthash:8].js',
        clean: true
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
            }
        ]
    }
};