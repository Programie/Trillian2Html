# Trillian2Html

A script to convert Trillian chat logs to HTML files.

## Requirements

* PHP (cli)
* PHP DOM extension
* Composer (to install the PHP dependencies)

## Installation

Just execute `composer.phar install` to install the dependencies.

## Usage

Execute `convert.php` with the following arguments:

* `<own username>`: Your own username
* `<path to Trillian logs dir>`: Path to your local Trillian logs folder (the one containing folders like `_CLOUD` and `ASTRA`)
* `<output dir>`: The path to the folder where to put the resulting HTML files