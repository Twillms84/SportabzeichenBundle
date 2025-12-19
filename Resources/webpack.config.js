/**
 * PulsR Sportabzeichen Bundle – Webpack-Konfiguration
 *
 * Funktioniert sowohl mit IServs Buildsystem (iservmake assets_v3)
 * als auch manuell (npx webpack --mode production)
 */

let glob = require('glob');
let path = require('path');
const { merge } = require('webpack-merge'); // <--- KORRIGIERT!
// ------------------------------------------------------------
// Fallback für manuelle Builds außerhalb von IServ
// ------------------------------------------------------------
const basePathCandidates = [
    process.env.WEBPACK_BASE_PATH,
    '/usr/lib/iserv/webpack',
    '/usr/lib/iserv/buildtools/webpack'
].filter(Boolean);

let baseConfig = null;
for (const candidate of basePathCandidates) {
    try {
        baseConfig = require(path.join(candidate, 'webpack.config.base.js'));
        console.log('✅ IServ base config gefunden unter:', candidate);
        break;
    } catch (e) {
        // still try next candidate
    }
}

if (!baseConfig) {
    console.warn('⚠️  Keine IServ base config gefunden – benutze lokale Fallback-Konfiguration.');
    baseConfig = {
        get: function (contextDir) {
            return {
                mode: 'production',
                output: {
                    path: path.resolve(contextDir, 'public'),
                    filename: '[name].js',
                    publicPath: '',
                    clean: true,
                },
            };
        },
    };
}

// ------------------------------------------------------------
// Hilfsfunktion: Alle Dateien aus assets/* einlesen
// ------------------------------------------------------------
const makeEntryPoints = function (globDir, prefix) {
    let ret = {};
    const files = glob.sync(path.join(__dirname, globDir));

    files.forEach(function (file) {
        const name = path.basename(file, path.extname(file));
        ret[prefix + '/' + name] = '.' + file.substring(__dirname.length);
    });

    return ret;
};

// ------------------------------------------------------------
// Modul-spezifische Definition
// ------------------------------------------------------------
let webpackConfig = {
    entry: merge(
        makeEntryPoints('./assets/css/*.css', 'css'),
        makeEntryPoints('./assets/css/*.less', 'css'),
        makeEntryPoints('./assets/js/*.js', 'js')
    ),
};

// ------------------------------------------------------------
// Merge mit IServ-Basiskonfiguration und exportieren
// ------------------------------------------------------------
module.exports = merge(baseConfig.get(__dirname), webpackConfig);
