// /usr/share/iserv/web/modules/PulsR/SportabzeichenBundle/webpack.config.js
const Encore = require('@symfony/webpack-encore');

Encore
    .setOutputPath('Resources/public/build/')
    .setPublicPath('/iserv/public/modules/PulsR/SportabzeichenBundle/build')
    .addEntry('exam_results_autosave', './Resources/public/js/exam_results_autosave.js')
    .enableSingleRuntimeChunk()
    .enableSourceMaps(!Encore.isProduction())
;

module.exports = Encore.getWebpackConfig();
