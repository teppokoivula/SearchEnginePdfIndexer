SearchEngine PDF Indexer add-on
-------------------------------

This module adds (experimental) PDF indexing support for the SearchEngine module.

Please note that in order to parse PDF files, we need to install some third party dependencies. Currently two PDF parsing libraries are supported: smalot/pdfparser and spatie/pdf-to-text. These are automatically installed along with this module when you install it via Composer, but if you install the module via file upload or using the modules manager in admin, please run `composer install` in the directory of the module after installing it.

Note also that spatie/pdf-to-text requires the pdftotext CLI tool, which needs to be installed on your OS. Please check out the spatie/pdf-to-text GitHub repository at https://github.com/spatie/pdf-to-text for more details.

## Usage

**WARNING**: this module is currently considered highly experimental. There's a good chance that installing it will cause fatal errors on your site. Please backup your data before installing the module and/or enabling it. If you run into any problems, please open a GitHub issue at https://github.com/teppokoivula/SearchEnginePdfIndexer/issues/new.

0) Install and configure [SearchEngine](https://github.com/teppokoivula/SearchEngine)
1) Install SearchEnginePdfIndexer, preferably via Composer (`composer require teppokoivula/search-engine-pdf-indexer`)
2) If you installed SearchEnginePdfIndexer via modules manager or file upload, run `composer install` in the directory of the module
3) Configure SearchEnginePdfIndexer

## Installing

This module can be installed by downloading or cloning the PageRenderFindReplace directory into the /site/modules/ directory of your site, but the recommended method installign it using Composer: `composer require teppokoivula/search-engine-pdf-indexer`. Composer installation takes care of dependencies automatically, which makes following steps easier.

## License

This project is licensed under the Mozilla Public License Version 2.0. For licensing of any third party dependencies that this module interfaces with, see their respective README or LICENSE files.
