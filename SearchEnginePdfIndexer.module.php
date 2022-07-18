<?php namespace ProcessWire;

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * SearchEngine PDF Indexer add-on
 *
 * This module adds (experimental) PDF indexing support for the SearchEngine module.
 *
 * Please note that in order to parse PDF files, we need to install some third party dependencies. Currently two PDF
 * parsing libraries are supported: smalot/pdfparser and spatie/pdf-to-text. These are automatically installed along
 * with this module when you install it via Composer, but if you install the module via file upload or using the
 * modules manager in admin, please run `composer install` in the directory of the module after installing it.
 *
 * Note also that spatie/pdf-to-text requires the pdftotext CLI tool, which needs to be installed on your OS. Please
 * check out the spatie/pdf-to-text GitHub repository at https://github.com/spatie/pdf-to-text for more details.
 *
 * @license Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */
class SearchEnginePdfIndexer extends WireData implements Module, ConfigurableModule {

    /**
     * PHP max execution time
     *
     * @var int|null
     */
    protected $php_max_execution_time = null;

    /**
     * PHP memory limit
     *
     * @var int|null
     */
    protected $php_memory_limit = null;

    /**
     * Available indexing methods
     *
     * @var array
     */
    protected $indexing_methods = [
        'disabled' => [
            'label' => '__Disabled', // $this->_('Disabled')
            'value' => 'disabled',
            'class' => null,
            'enabled' => true,
        ],
        'smalot-pdfparser' => [
            'label' => 'smalot/pdfparser',
            'value' => 'smalot-pdfparser',
            'class' => '\Smalot\PdfParser\Parser',
            'enabled' => false,
        ],
        'spatie-pdf-to-text' => [
            'label' => 'spatie/pdf-to-text',
            'value' => 'spatie-pdf-to-text',
            'class' => '\Spatie\PdfToText\Pdf',
            'enabled' => false,
        ],
    ];

    /**
     * Number of enabled indexing methods
     *
     * @var int
     */
    protected $num_enabled_indexing_methods = 0;

    /**
     * Module info
     *
     * @return array
     */
    public static function getModuleInfo() {
        return [
            'title' => 'SearchEngine PDF Indexer',
            'summary' => 'SearchEngine add-on for indexing PDF files (experimental)',
            'icon' => 'file-pdf-o',
            'version' => '0.0.3',
            'requires' => 'PHP>=7.4, ProcessWire>=3.0.164, SearchEngine>=0.33.0',
            'autoload' => true,
        ];
    }

    /**
     * Constructor, set defaults
     */
    public function __construct() {
        $this->num_enabled_indexing_methods = 0;
        foreach ($this->indexing_methods as &$indexing_method) {
            if (!empty($indexing_method['class']) && class_exists($indexing_method['class'])) {
                $indexing_method['enabled'] = true;
                ++$this->num_enabled_indexing_methods;
            }
            if (strpos($indexing_method['label'], '__') === 0) {
                $indexing_method['label'] = $this->_(substr($indexing_method['label'], 2));
            }
        }
        $this->file_extensions = 'pdf';
        $this->indexing_method = 'disabled';
        $this->discard_builtin_index = 'no';
        if ($this->num_enabled_indexing_methods) {
            if (function_exists('ini_get')) {
                $this->php_max_execution_time = (int) ini_get('max_execution_time');
                $this->php_memory_limit = $this->getPHPMemoryLimit();
            }
            $this->spatie_pdf_to_text_timeout = 60;
            if (!empty($this->php_max_execution_time) && $this->php_max_execution_time < $this->spatie_pdf_to_text_timeout) {
                $this->spatie_pdf_to_text_timeout = $this->php_max_execution_time;
            }
            if (!empty($this->php_memory_limit) && $this->php_memory_limit < $this->smalot_pdfparser_decode_memory_limit) {
                $this->smalot_pdfparser_decode_memory_limit = $this->smalot_pdfparser_decode_memory_limit;
            }
        }
    }

    /**
     * Init method
     */
    public function init() {
        if ($this->indexing_method != 'disabled' && $this->indexing_methods[$this->indexing_method]['enabled']) {
            $this->addHookAfter('Indexer::getPagefileIndexValue', $this, 'indexFile');
        }
    }

    /**
     * If Pagefile is PDF, attempt to index it
     *
     * @param HookEvent $event
     */
    protected function indexFile(HookEvent $event) {

        // Get and validate file
        $file = $event->arguments[0];
        if (!$this->isValidFile($file)) {
            return;
        }

        // Attempt to read file data using selected indexing method
        $file_data = null;
        if ($this->indexing_method === 'smalot-pdfparser') {
            $file_data = $this->indexFileSmalotPdfParser($file);
        } else if ($this->indexing_method === 'spatie-pdf-to-text') {
            $file_data = $this->indexFileSpatiePdfToText($file);
        }

        // Check if built-in SearchEngine index value should be discarded
        if ($this->discard_builtin_index == 'yes' || ($this->discard_builtin_index == 'yes_if' && !empty($file_data))) {
            $event->return = $file_data;
            return;
        }

        // Return combination of built-in index value and read file data
        $event->return = implode(' ... ', array_filter([
            $event->return,
            $file_data,
        ]));
    }

    /**
     * Index file with smalot/pdfparser
     *
     * @param Pagefile $file
     * @return mixed
     */
    protected function indexFileSmalotPdfParser(Pagefile $file) {
        try {
            $config = new \Smalot\PdfParser\Config();
            if (!empty($this->smalot_pdfparser_decode_memory_limit)) {
                $config->setDecodeMemoryLimit((int) $this->smalot_pdfparser_decode_memory_limit);
            }
            $config->setRetainImageContent(false);
            $parser = new \Smalot\PdfParser\Parser([], $config);
            $pdf = $parser->parseFile($file->filename);
            // Call PHP garbage collector after parsing file to alleviate memory leak issue (see
            // https://github.com/smalot/pdfparser/issues/104 for more details).
            gc_collect_cycles();
            return $pdf->getText();
        } catch (\Exception $e) {
            $this->log->error(sprintf(
                'SearchEnginePdfIndexer::indexFileSmalotPdfParser error for file at %s: %s',
                $file->filename,
                $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * Index file with spatie/pdf-to-text
     *
     * @param Pagefile $file
     * @return mixed
     */
    protected function indexFileSpatiePdfToText(Pagefile $file) {
        try {
            // pdftotext path and options are intentionally limited to config, which would normally require access to code.
            // Said values be potentially dangerous, so we're not going to allow freely modifying them via the config GUI.
            return \Spatie\PdfToText\Pdf::getText(
                $file->filename,
                $this->config->SearchEnginePdfIndexer['spatie_pdf_to_text_path'] ?? null,
                $this->config->SearchEnginePdfIndexer['spatie_pdf_to_text_options'] ?? [
                    'nopgbrk',
                ],
                (int) $this->spatie_pdf_to_text_timeout ?: 60
            );
        } catch (\Exception $e) {
            $this->log->error(sprintf(
                'SearchEnginePdfIndexer::indexFileSpatiePdfToText error for file at %s: %s',
                $file->filename,
                $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * Check if file is valid and can be indexed
     *
     * @param string|Pagefile $file
     * @return bool
     */
    protected function ___isValidFile($file): bool {
        return in_array(strtolower($file->ext), explode(' ', $this->file_extensions)) && (!$this->max_file_size || $file->filesize <= $this->max_file_size);
    }

    /**
     * Module config inputfields
     *
     * @param InputfieldWrapper $inputfields
     */
    public function getModuleConfigInputfields(InputfieldWrapper $inputfields) {

        /** @var InputfieldText */
        $file_extensions = $this->modules->get('InputfieldText');
        $file_extensions->name = 'file_extensions';
        $file_extensions->label = $this->_('File extensions');
        $file_extensions->description = $this->_('List of file extensions we want to process.');
        $file_extensions->notes = $this->_('If there are multiple values, use space as a separator (`ext1 ext2 ext3`).');
        $file_extensions->value = $this->file_extensions;
        $inputfields->add($file_extensions);

        /** @var InputfieldRadios */
        $indexing_method = $this->modules->get('InputfieldRadios');
        $indexing_method->name = 'indexing_method';
        $indexing_method->label = $this->_('Indexing method');
        foreach ($this->indexing_methods as $method) {
            $indexing_method->addOption($method['value'], $method['label'], [
                'disabled' => !$method['enabled'],
            ]);
            if (!$method['enabled'] && $this->indexing_method == $method['value']) {
                $this->indexing_method = 'disabled';
            }
        }
        $indexing_method->optionColumns = 1;
        $indexing_method->value = $this->indexing_method;
        if (!$this->num_enabled_indexing_methods) {
            $indexing_method->notes = sprintf(
                $this->_('There are currently no indexing libraries available. Please make sure that you have installed this module via Composer, or alternatively executed `composer install` in the module\'s directory (`%s`).'),
                $this->config->paths->{$this->className()}
            );
        } else if ($this->indexing_methods['spatie-pdf-to-text']['enabled']) {
            $indexing_method->notes = $this->_('Note: spatie/pdf-to-text requires installing the pdftotext CLI tool on your operating system. See [spatie/pdf-to-text GitHub repository](https://github.com/spatie/pdf-to-text) for more details.');
        }
        $inputfields->add($indexing_method);

        /** @var InputfieldInteger */
        $smalot_pdfparser_decode_memory_limit = $this->modules->get('InputfieldInteger');
        $smalot_pdfparser_decode_memory_limit->name = 'smalot_pdfparser_decode_memory_limit';
        $smalot_pdfparser_decode_memory_limit->type = 'number';
        $smalot_pdfparser_decode_memory_limit->label = $this->_('Memory limit for decoding operations (smalot/pdfparser)');
        $smalot_pdfparser_decode_memory_limit->description = $this->_('In order to avoid memory running out while indexing individual files, you can set a lower memory limit for decoding operations.');
        $smalot_pdfparser_decode_memory_limit->notes = $this->_('Provide value in bytes or leave empty to disable limit. `1048576` = 1 MiB, `5242880` = 5 MiB, `31457280` = 30 MiB etc.');
        $smalot_pdfparser_decode_memory_limit->showIf = 'indexing_method=smalot-pdfparser';
        if (!$this->indexing_methods['smalot-pdfparser']['enabled']) {
            $smalot_pdfparser_decode_memory_limit->showIf .= '--disabled';
        }
        $smalot_pdfparser_decode_memory_limit->value = $this->smalot_pdfparser_decode_memory_limit;
        if (!empty($this->php_memory_limit)) {
            $smalot_pdfparser_decode_memory_limit->notes .= "\n\n" . sprintf(
                $this->_('Current PHP memory limit is `%d` bytes = %d MiB. As a precaution the value of this setting cannot be higher than PHP memory limit.'),
                $this->php_memory_limit,
                $this->php_memory_limit / 1024 / 1024
            );
            if (!$smalot_pdfparser_decode_memory_limit->value || $smalot_pdfparser_decode_memory_limit->value > $this->php_memory_limit) {
                $smalot_pdfparser_decode_memory_limit->value = $this->php_memory_limit;
            }
            $smalot_pdfparser_decode_memory_limit->max = $this->php_memory_limit;
        }
        $inputfields->add($smalot_pdfparser_decode_memory_limit);

        /** @var InputfieldInteger */
        $spatie_pdf_to_text_timeout = $this->modules->get('InputfieldInteger');
        $spatie_pdf_to_text_timeout->name = 'spatie_pdf_to_text_timeout';
        $spatie_pdf_to_text_timeout->type = 'number';
        $spatie_pdf_to_text_timeout->label = $this->_('Timeout in seconds (spatie/pdf-to-text)');
        $spatie_pdf_to_text_timeout->description = $this->_('In order to avoid timeouts while indexing individual files, set preferred timeout in seconds.');
        $spatie_pdf_to_text_timeout->notes = sprintf(
            $this->_('Default value is `%d` seconds.'),
            60
        );
        $spatie_pdf_to_text_timeout->value = $this->spatie_pdf_to_text_timeout;
        if (!empty($this->php_max_execution_time)) {
            $spatie_pdf_to_text_timeout->notes .= "\n\n" . sprintf(
                $this->_('Current PHP max execution time is `%d` seconds. As a precaution the value of this setting cannot be higher than PHP max execution time.'),
                $this->php_max_execution_time
            );
            if (!$spatie_pdf_to_text_timeout->value || $spatie_pdf_to_text_timeout->value > $this->php_max_execution_time) {
                $spatie_pdf_to_text_timeout->value = $this->php_max_execution_time;
            }
            $spatie_pdf_to_text_timeout->max = $this->php_max_execution_time;
        }
        $spatie_pdf_to_text_timeout->showIf = 'indexing_method=spatie-pdf-to-text';
        if (!$this->indexing_methods['spatie-pdf-to-text']['enabled']) {
            $spatie_pdf_to_text_timeout->showIf .= '--disabled';
        }
        $inputfields->add($spatie_pdf_to_text_timeout);

        /** @var InputfieldInteger */
        $max_file_size = $this->modules->get('InputfieldInteger');
        $max_file_size->name = 'max_file_size';
        $max_file_size->type = 'number';
        $max_file_size->label = $this->_('Maximum file size to process');
        $max_file_size->description = $this->_('In order to avoid memory running out while indexing individual files, you can define maximum file size to process.');
        $max_file_size->notes = $this->_('Provide value in bytes or leave empty to disable limit. `1048576` = 1 MiB, `5242880` = 5 MiB, `31457280` = 30 MiB etc.');
        $max_file_size->showIf = 'indexing_method!=disabled';
        $max_file_size->value = $this->max_file_size;
        $inputfields->add($max_file_size);

        /** @var InputfieldRadios */
        $discard_builtin_index = $this->modules->get('InputfieldRadios');
        $discard_builtin_index->name = 'discard_builtin_index';
        $discard_builtin_index->label = $this->_('Discard built-in index?');
        $discard_builtin_index->description = $this->_('By default file data is appended to the built-in SearchEngine file index value. If you want to discard built-in file index, select appropriate option for this setting.');
        $discard_builtin_index->showIf = 'indexing_method!=disabled';
        $discard_builtin_index->addOptions([
            'no' => $this->_('No'),
            'yes' => $this->_('Yes'),
            'yes_if' => $this->_('If file data can be read'),
        ]);
        $discard_builtin_index->optionColumns = 1;
        $discard_builtin_index->value = $this->discard_builtin_index;
        $inputfields->add($discard_builtin_index);
    }

    /**
     * Get PHP memory limit
     *
     * @return int|null
     */
    protected function getPHPMemoryLimit(): ?int {
        $memory_limit = ini_get('memory_limit');
        if (empty($memory_limit) || (is_numeric($memory_limit) && $memory_limit <= 0)) {
            return null;
        }
        $memory_limit = trim(strtolower($memory_limit));
        if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches)) {
            if ($matches[2] === 'g') {
                $memory_limit = $matches[1] * 1024 * 1024 * 1024;
            } else if ($matches[2] === 'm') {
                $memory_limit = $matches[1] * 1024 * 1024;
            } else if ($matches[2] === 'k') {
                $memory_limit = $matches[1] * 1024;
            }
        }
        return (int) $memory_limit;
    }

}
