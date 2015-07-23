<?php namespace Backend\Behaviors;

use Str;
use Lang;
use Backend\Classes\ControllerBehavior;
use League\Csv\Reader as CsvReader;
use ApplicationException;
use Exception;

/**
 * Import/Export Controller Behavior
 * Adds features for importing and exporting data.
 *
 * @package october\backend
 * @author Alexey Bobkov, Samuel Georges
 */
class ImportExportController extends ControllerBehavior
{

    /**
     * {@inheritDoc}
     */
    protected $requiredProperties = ['importExportConfig'];

    /**
     * @var array Configuration values that must exist when applying the primary config file.
     */
    protected $requiredConfig = [];

    /**
     * @var Model Import model
     */
    public $importModel;

    /**
     * @var array Import column configuration.
     */
    public $importColumns;

    /**
     * @var Backend\Classes\WidgetBase Reference to the widget used for uploading import file.
     */
    protected $importUploadFormWidget;

    /**
     * @var Backend\Classes\WidgetBase Reference to the widget used for specifying import options.
     */
    protected $importOptionsFormWidget;

    /**
     * @var Model Export model
     */
    public $exportModel;

    /**
     * @var array Export column configuration.
     */
    public $exportColumns;

    /**
     * @var string File name used for export output.
     */
    protected $exportFileName = 'export.csv';

    /**
     * @var Backend\Classes\WidgetBase Reference to the widget used for standard export options.
     */
    protected $exportFormatFormWidget;

    /**
     * @var Backend\Classes\WidgetBase Reference to the widget used for custom export options.
     */
    protected $exportOptionsFormWidget;

    /**
     * Behavior constructor
     * @param Backend\Classes\Controller $controller
     */
    public function __construct($controller)
    {
        parent::__construct($controller);

        $this->addJs('js/october.import.js', 'core');
        $this->addJs('js/october.export.js', 'core');
        $this->addCss('css/import.css', 'core');
        $this->addCss('css/export.css', 'core');

        /*
         * Build configuration
         */
        $this->config = $this->makeConfig($controller->importExportConfig, $this->requiredConfig);

        /*
         * Process config
         */
        if ($exportFileName = $this->getConfig('export[fileName]')) {
            $this->exportFileName = $exportFileName;
        }

        /*
         * Import form widgets
         */
        if ($this->importUploadFormWidget = $this->makeImportUploadFormWidget()) {
            $this->importUploadFormWidget->bindToController();
        }

        if ($this->importOptionsFormWidget = $this->makeImportOptionsFormWidget()) {
            $this->importOptionsFormWidget->bindToController();
        }

        /*
         * Export form widgets
         */
        if ($this->exportFormatFormWidget = $this->makeExportFormatFormWidget()) {
            $this->exportFormatFormWidget->bindToController();
        }

        if ($this->exportOptionsFormWidget = $this->makeExportOptionsFormWidget()) {
            $this->exportOptionsFormWidget->bindToController();
        }
    }

    //
    // Controller actions
    //

    public function import()
    {
        $this->controller->pageTitle = $this->controller->pageTitle
            ?: Lang::get($this->getConfig('import[title]', 'Import records'));

        $this->prepareImportVars();
    }

    public function export()
    {
        $this->controller->pageTitle = $this->controller->pageTitle
            ?: Lang::get($this->getConfig('export[title]', 'Export records'));

        $this->prepareExportVars();
    }

    public function download($name, $outputName = null)
    {
        $this->controller->pageTitle = $this->controller->pageTitle
            ?: Lang::get($this->getConfig('export[title]', 'Export records'));

        return $this->exportGetModel()->download($name, $outputName);
    }

    //
    // Importing AJAX
    //

    public function onImport()
    {
        try {
            $model = $this->importGetModel();
            $matches = post('column_match', []);

            if ($optionData = post('ImportOptions')) {
                $model->fill($optionData);
            }

            $model->import($matches, [
                'sessionKey' => $this->importUploadFormWidget->getSessionKey(),
                'firstRowTitles' => post('first_row_titles', false)
            ]);

            $this->vars['importResults'] = $model->getResultStats();
        }
        catch (Exception $ex) {
            $this->controller->handleError($ex);
        }

        return $this->importExportMakePartial('import_result_form');
    }

    public function onImportLoadForm()
    {
        try {
            $this->checkRequiredImportColumns();
        }
        catch (Exception $ex) {
            $this->controller->handleError($ex);
        }

        return $this->importExportMakePartial('import_form');
    }

    public function onImportLoadColumnSampleForm()
    {
        if (($columnId = post('file_column_id', false)) === false) {
            throw new ApplicationException('Missing column identifier');
        }

        $columns = $this->getImportFileColumns();
        if (!array_key_exists($columnId, $columns)) {
            throw new ApplicationException('Unknown column');
        }

        $path = $this->getImportFilePath();
        $reader = CsvReader::createFromPath($path);

        if (post('first_row_titles')) {
            $reader->setOffset(1);
        }

        $data = $reader->setLimit(50)->fetchColumn((int) $columnId);

        /*
         * Clean up data
         */
        foreach ($data as $index => $sample) {
            $data[$index] = Str::limit($sample, 100);
            if (!strlen($data[$index])) {
                unset($data[$index]);
            }
        }

        $this->vars['columnName'] = array_get($columns, $columnId);
        $this->vars['columnData'] = $data;

        return $this->importExportMakePartial('column_sample_form');
    }

    //
    // Importing
    //

    /**
     * Prepares the view data.
     * @return void
     */
    public function prepareImportVars()
    {
        $this->vars['importUploadFormWidget'] = $this->importUploadFormWidget;
        $this->vars['importOptionsFormWidget'] = $this->importOptionsFormWidget;
        $this->vars['importDbColumns'] = $this->getImportDbColumns();
        $this->vars['importFileColumns'] = $this->getImportFileColumns();

        // Make these variables available to widgets
        $this->controller->vars += $this->vars;
    }

    public function importRender()
    {
        return $this->importExportMakePartial('import');
    }

    public function importGetModel()
    {
        return $this->getModelForType('import');
    }

    protected function getImportDbColumns()
    {
        if ($this->importColumns !== null) {
            return $this->importColumns;
        }
        $columnConfig = $this->getConfig('import[list]');
        return $this->importColumns = $this->makeListColumns($columnConfig);
    }

    protected function getImportFileColumns()
    {
        if (!$path = $this->getImportFilePath()) {
            return null;
        }

        $reader = CsvReader::createFromPath($path);
        $firstRow = $reader->fetchOne(0);

        if (!post('first_row_titles')) {
            array_walk($firstRow, function(&$value, $key) {
                $value = 'Column #'.($key + 1);
            });
        }

        return $firstRow;
    }

    protected function makeImportUploadFormWidget()
    {
        if (!$this->getConfig('import')) {
            return null;
        }

        $widgetConfig = $this->makeConfig('~/modules/backend/behaviors/importexportcontroller/partials/fields_import.yaml');
        $widgetConfig->model = $this->importGetModel();
        $widgetConfig->alias = 'importUploadForm';

        $widget = $this->makeWidget('Backend\Widgets\Form', $widgetConfig);

        $widget->bindEvent('form.beforeRefresh', function($holder) {
            $holder->data = [];
        });

        return $widget;
    }

    protected function makeImportOptionsFormWidget()
    {
        $widget = $this->makeOptionsFormWidgetForType('import');

        if (!$widget && $this->importUploadFormWidget) {
            $stepSection = $this->importUploadFormWidget->getField('step3_section');
            $stepSection->hidden = true;
        }

        return $widget;
    }

    protected function getImportFilePath()
    {
        return $this
            ->importGetModel()
            ->getImportFilePath($this->importUploadFormWidget->getSessionKey());
    }

    public function importIsColumnRequired($columnName)
    {
        $model = $this->importGetModel();
        return $model->isAttributeRequired($columnName);
    }

    protected function checkRequiredImportColumns()
    {
        if (!$matches = post('column_match', [])) {
            throw new ApplicationException('Please match some columns first.');
        }

        $dbColumns = $this->getImportDbColumns();
        foreach ($dbColumns as $column => $label) {
            if (!$this->importIsColumnRequired($column)) continue;

            $found = false;
            foreach ($matches as $matchedColumns) {
                if (in_array($column, $matchedColumns)) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                throw new ApplicationException('Please specify a match for the required field '.$label.'.');
            }
        }
    }

    //
    // Exporting AJAX
    //

    public function onExport()
    {
        try {
            $model = $this->exportGetModel();
            $columns = $this->processExportColumnsFromPost();
            $exportOptions = [
                'sessionKey' => $this->exportFormatFormWidget->getSessionKey()
            ];

            if ($optionData = post('ExportOptions')) {
                $model->fill($optionData);
            }

            if (post('format_preset') == 'custom') {
                $exportOptions['delimiter'] = post('format_delimiter');
                $exportOptions['enclosure'] = post('format_enclosure');
                $exportOptions['escape'] = post('format_escape');
            }

            $reference = $model->export($columns, $exportOptions);

            $this->vars['fileUrl'] = $this->controller->actionUrl(
                'download',
                $reference.'/'.$this->exportFileName
             );
        }
        catch (Exception $ex) {
            $this->controller->handleError($ex);
        }

        return $this->importExportMakePartial('export_result_form');
    }

    public function onExportLoadForm()
    {
        return $this->importExportMakePartial('export_form');
    }

    //
    // Exporting
    //

    /**
     * Prepares the view data.
     * @return void
     */
    public function prepareExportVars()
    {
        $this->vars['exportFormatFormWidget'] = $this->exportFormatFormWidget;
        $this->vars['exportOptionsFormWidget'] = $this->exportOptionsFormWidget;
        $this->vars['exportColumns'] = $this->getExportColumns();

        // Make these variables available to widgets
        $this->controller->vars += $this->vars;
    }

    public function exportRender()
    {
        return $this->importExportMakePartial('export');
    }

    public function exportGetModel()
    {
        return $this->getModelForType('export');
    }

    protected function getExportColumns()
    {
        if ($this->exportColumns !== null) {
            return $this->exportColumns;
        }
        $columnConfig = $this->getConfig('export[list]');
        return $this->exportColumns = $this->makeListColumns($columnConfig);
    }

    protected function makeExportFormatFormWidget()
    {
        if (!$this->getConfig('export')) {
            return null;
        }

        $widgetConfig = $this->makeConfig('~/modules/backend/behaviors/importexportcontroller/partials/fields_export.yaml');
        $widgetConfig->model = $this->exportGetModel();
        $widgetConfig->alias = 'exportUploadForm';

        $widget = $this->makeWidget('Backend\Widgets\Form', $widgetConfig);

        $widget->bindEvent('form.beforeRefresh', function($holder) {
            $holder->data = [];
        });

        return $widget;
    }

    protected function makeExportOptionsFormWidget()
    {
        $widget = $this->makeOptionsFormWidgetForType('export');

        if (!$widget && $this->exportFormatFormWidget) {
            $stepSection = $this->exportFormatFormWidget->getField('step3_section');
            $stepSection->hidden = true;
        }

        return $widget;
    }

    protected function processExportColumnsFromPost()
    {
        $visibleColumns = post('visible_columns', []);
        $columns = post('export_columns', []);

        foreach ($columns as $key => $columnName) {
            if (!isset($visibleColumns[$columnName])) {
                unset($columns[$key]);
            }
        }

        $result = [];
        $definitions = $this->getExportColumns();

        foreach ($columns as $column) {
            $result[$column] = array_get($definitions, $column, '???');
        }

        return $result;
    }

    //
    // Helpers
    //

    /**
     * Controller accessor for making partials within this behavior.
     * @param string $partial
     * @param array $params
     * @return string Partial contents
     */
    public function importExportMakePartial($partial, $params = [])
    {
        $contents = $this->controller->makePartial('import_export_'.$partial, $params + $this->vars, false);
        if (!$contents) {
            $contents = $this->makePartial($partial, $params);
        }

        return $contents;
    }

    protected function makeOptionsFormWidgetForType($type)
    {
        if (!$this->getConfig($type)) {
            return null;
        }

        if ($fieldConfig = $this->getConfig($type.'[form]')) {
            $widgetConfig = $this->makeConfig($fieldConfig);
            $widgetConfig->model = $this->getModelForType($type);
            $widgetConfig->alias = $type.'OptionsForm';
            $widgetConfig->arrayName = ucfirst($type).'Options';

            $widget = $this->makeWidget('Backend\Widgets\Form', $widgetConfig);
            return $widget;
        }

        return null;
    }

    protected function getModelForType($type)
    {
        $cacheProperty = $type.'Model';

        if ($this->{$cacheProperty} !== null) {
            return $this->{$cacheProperty};
        }

        $modelClass = $this->getConfig($type.'[modelClass]');
        if (!$modelClass) {
            throw new ApplicationException('Please specify the modelClass property for '.$type);
        }

        return $this->{$cacheProperty} = new $modelClass;
    }

    protected function makeListColumns($config)
    {
        $config = $this->makeConfig($config);

        if (!isset($config->columns) || !is_array($config->columns)) {
            return null;
        }

        $result = [];
        foreach ($config->columns as $attribute => $column) {
            $result[$attribute] = array_get($column, 'label', $attribute);
        }

        return $result;
    }
}