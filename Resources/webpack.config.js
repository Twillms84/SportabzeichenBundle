let merge = require('webpack-merge');
let path = require('path');

// Wir suchen die IServ-Basis-Config dort, wo sie auf dem Server wirklich liegt
const baseConfigPath = '/usr/share/iserv/web/vendor/iserv/core-bundle/Resources/webpack/webpack.config.base.js';
let baseConfig = require(baseConfigPath);

let webpackConfig = {
    // Hier listest du alle deine Dateien auf
    entry: {
        'js/exam_results_autosave': './assets/js/exam_results_autosave.js',
        // 'js/andere_datei': './assets/js/andere_datei.js', 
    },
    output: {
        // WICHTIG für Drittanbieter: Direkt in den IServ-Asset-Ordner
        path: path.resolve('/usr/share/iserv/web/public/assets/pulsr-sportabzeichen'),
        publicPath: '/assets/pulsr-sportabzeichen/'
    }
};

module.exports = merge(baseConfig.get(__dirname), webpackConfig);