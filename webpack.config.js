const path = require("path");
const MiniCssExtractPlugin = require("mini-css-extract-plugin");
const CopyPlugin = require("copy-webpack-plugin");
const TerserPlugin = require("terser-webpack-plugin");
const CssMinimizerPlugin = require("css-minimizer-webpack-plugin");

const isProduction = process.env.NODE_ENV === "production";

module.exports = {
    mode: isProduction ? "production" : "development",
    entry: {
        app: "./Resources/asset/js/webpack-app.js",
        forum: "./Resources/asset/js/webpack-forum.js",
        thread: "./Resources/asset/js/webpack-thread.js",
        post: "./Resources/asset/js/webpack-post.js",
        theme_green: "./Resources/asset/scss/theme_green.scss",
        theme_dark_blue: "./Resources/asset/scss/theme_dark_blue.scss"
    },
    output: {
        path: path.resolve(__dirname, "./Resources/public"),
        filename: "[name].min.js"
    },
    module: {
        rules: [
            {
                test: /\.js$/,
                exclude: /(node_modules|bower_components)/,
                use: {
                    loader: "babel-loader",
                    options: {
                        presets: ["@babel/preset-env"]
                    }
                }
            },
            {
                test: /\.(scss|css)$/,
                use: [
                    MiniCssExtractPlugin.loader,
                    "css-loader",
                    // postcss-loader was in devDependencies and in
                    // postcss.config.js but never in the chain, so autoprefixer
                    // never ran. It is wired in here, between css-loader and
                    // the compiler, which is the only place it can see finished
                    // CSS and still be processed by css-loader afterwards.
                    "postcss-loader",
                    "sass-loader"
                ]
            },
            // Webpack 5 has asset modules built in; file-loader and url-loader
            // were separate packages that it replaces. `asset/resource` is what
            // file-loader did.
            {
                test: /\.(ttf|woff|woff2|eot)$/,
                type: "asset/resource",
                generator: {
                    filename: "font/[name][ext]",
                    publicPath: "../font/"
                }
            },
            {
                test: /\.(svg|png|jpe?g|gif)$/,
                type: "asset/resource",
                generator: {
                    filename: "images/[name][ext]",
                    publicPath: "../images/"
                }
            }
        ]
    },
    optimization: {
        // Webpack 5 minifies with terser on its own in production. Naming the
        // minimizers explicitly is what lets CSS be minified too: setting
        // `minimizer` replaces the defaults, so terser has to be listed
        // alongside CssMinimizerPlugin or the JS would stop being minified.
        minimize: isProduction,
        minimizer: [new TerserPlugin(), new CssMinimizerPlugin()]
    },
    plugins: [
        new MiniCssExtractPlugin({
            filename: "css/[name].min.css"
        }),
        // copy-webpack-plugin 6 moved its argument to { patterns: [...] };
        // passing the bare array silently copied nothing.
        new CopyPlugin({
            patterns: [{ from: "./Resources/asset/images", to: "./images/" }]
        })
    ]
};
