<?php
namespace TmlpStats\Import\Xlsx\ImportDocument;

use TmlpStats\Import\ImportDocument;
use TmlpStats\Validate;
use PHPExcel_IOFactory;
use Carbon\Carbon;

abstract class ImportDocumentAbstract extends \TmlpStats\Import\ImportDocument
{
    const TAB_WEEKLY_STATS       = 0;
    const TAB_CLASS_LIST         = 1;
    const TAB_COURSES            = 2;
    const TAB_LOCAL_TEAM_CONTACT = 3;

    const TYPE_NORTHAMERICA      = 0;
    const TYPE_INTERNATIONAL     = 1;

    protected $xlsType = 'Excel2007'; // Same format for Excel 2010

    protected $sheetNameType = ImportDocumentAbstract::TYPE_NORTHAMERICA;

    protected $naSheetNames = array('Current Weekly Stats', 'Class List', 'CAP & CPC Course Info.', 'Local Team Contact Info.');
    protected $intSheetNames = array('Current Weekly Perf. Measures', 'Class List', 'Centre Courses Info.', 'Local Team Contact Info.');
    protected $sheets = array(null, null, null, null);

    protected $version = null;
    protected $expectedDate = null;
    protected $enforceVersion = true;

    protected $messages = array(
        'errors' => array(),
        'warnings' => array(),
    );

    abstract protected function loadVersion();

    public function __construct($file, $expectedDate = null, $enforceVersion = true)
    {
        $this->loadWorkbook($file);

        $this->loadVersion();

        $this->expectedDate = $expectedDate;
        $this->enforceVersion = $enforceVersion;
    }

    public function import($validateReport = true)
    {
        $isValid = false;
        $this->process();


        if ($this->statsReport) {
            if (!$validateReport || $this->validateReport()) {
                $this->postProcess();
                $this->statsReport->validated = true;
                $isValid =  true;
            } else {
                $this->statsReport->validated = false;
            }
            $this->statsReport->save();

            if (defined('VALIDATE_ONLY') || !$isValid || !$this->isValid()) {
                // Flush all data imported from this report. Keeps db clean from
                // data left over from invalid sheets
                $this->statsReport->clear();
            }
        }

        // Check if there were any errors during import
        if (!$this->isValid()) {
            $isValid = false;
        }

        $this->normalizeMessages();

        return $isValid;
    }

    public function __get($name)
    {
        switch($name) {

            case 'version': return $this->version;
            case 'messages': return $this->messages;
            default: return parent::__get($name);
        }
    }

    public function isValid()
    {
        return count($this->messages['errors']) == 0;
    }

    protected function validateReport()
    {
        $validator = $this->getValidator();
        return $validator->run($this->statsReport);
    }

    protected function getValidator($type = null)
    {
        return Validate\ValidatorFactory::build($this->version, $type);
    }

    protected function loadWorkbook($file)
    {
        $reader = PHPExcel_IOFactory::createReader($this->xlsType);
        $inputWorksheetNames = $reader->listWorksheetNames($file);

        // Verify document has only the expected worksheets
        if ($this->isNorthAmericaSheet($inputWorksheetNames)) {
            $this->sheetNameType = static::TYPE_NORTHAMERICA;
            $sheetNames = $this->naSheetNames;
        } else if ($this->isInternationalSheet($inputWorksheetNames)) {
            $this->sheetNameType = static::TYPE_INTERNATIONAL;
            $sheetNames = $this->intSheetNames;
        } else {
            throw new \Exception("Excel document doesn't appear to be a center stats report.");
        }

        // Make sure we can load the document
        $reader->setLoadSheetsOnly($sheetNames);
        $doc = $reader->load($file);
        if (!$doc) {
            throw new \Exception("Unable to load excel file.");
        }

        $this->loadAllSheets($doc);

        // Disconnect the worksheets to save memory (added for importing large numbers of sheets)
        $doc->disconnectWorksheets();
    }

    protected function isNorthAmericaSheet($inputWorksheetNames)
    {
        $expectedSheets = $this->naSheetNames;
        array_push($expectedSheets, 'Instructions - Revision History');

        return $this->hasExpectedSheets($inputWorksheetNames, $expectedSheets);
    }

    protected function isInternationalSheet($inputWorksheetNames)
    {
        $expectedSheets = $this->intSheetNames;
        array_push($expectedSheets, 'Instructions - Revision History');

        return $this->hasExpectedSheets($inputWorksheetNames, $expectedSheets);
    }

    protected function hasExpectedSheets($inputWorksheetNames, $expectedSheets)
    {
        $diff = array_diff($inputWorksheetNames, $expectedSheets);
        if (count($diff) > 0) {
            $ignoreExtra = true;
            foreach ($diff as $sheet) {

                // Ignore default sheets excel may add
                if (!preg_match('/^Sheet\d+$/', $sheet)) {
                    $ignoreExtra = false;
                }
            }

            if (!$ignoreExtra) {
                return false;
            }
        }
        return true;
    }

    protected function loadAllSheets($doc)
    {
        $sheetNames = ($this->sheetNameType == static::TYPE_NORTHAMERICA)
            ? $this->naSheetNames
            : $this->intSheetNames;

        $loadedCount = 0;
        for($i = 0; $i < count($sheetNames); $i++) {

            $sheet = $this->loadSheet($i, $doc);
            if (!is_array($sheet) || count($sheet) == 0) {
                throw new \Exception("Workbook is missing sheet '{$sheetNames[$i]}'");
            }
        }
    }

    protected function loadSheet($index, $doc=null)
    {
        $sheetNames = ($this->sheetNameType == static::TYPE_NORTHAMERICA)
            ? $this->naSheetNames
            : $this->intSheetNames;

        if ($this->sheets[$index] === null && $doc) {
            $sheet = $doc->getSheetByName($sheetNames[$index]);
            if (!$sheet) {
                throw new \Exception("Could not find sheet {$sheetNames[$index]}");
            } else {
                $this->sheets[$index] = $sheet->toArray(null,true,false,true);
            }
        }
        return $this->sheets[$index];
    }

    public function getSheetName($sheetId)
    {
        switch ($sheetId) {
            case static::TAB_WEEKLY_STATS:
            case static::TAB_CLASS_LIST:
            case static::TAB_COURSES:
            case static::TAB_LOCAL_TEAM_CONTACT:
                return $this->sheetNameType == static::TYPE_NORTHAMERICA
                    ? $this->naSheetNames[$sheetId]
                    : $this->intSheetNames[$sheetId];

            default:
                return 'unspecified';
        }
    }

    protected function normalizeMessages()
    {
        // Sort first so they are in tab order instead of ordered by tab name
        usort($this->messages['errors'], array(get_class($this), 'sortBySection'));
        usort($this->messages['warnings'], array(get_class($this), 'sortBySection'));

        foreach ($this->messages['errors'] as &$message) {
            $message['section'] = $this->getSheetName($message['section']);
        }
        foreach ($this->messages['warnings'] as &$message) {
            $message['section'] = $this->getSheetName($message['section']);
        }
    }

    protected static function sortBySection($a, $b)
    {
        return ($a['section'] >= $b['section']) ? 1 : -1;
    }

    protected function postProcess() { }
}
