<?php
namespace TmlpStats\Validate;

use TmlpStats\Import\Xlsx\ImportDocument\ImportDocument;
use Carbon\Carbon;
use Respect\Validation\Validator as v;

class CommCourseInfoValidator extends ValidatorAbstract
{
    protected $sheetId = ImportDocument::TAB_COURSES;

    protected function populateValidators($data)
    {
        $positiveIntValidator        = v::int()->min(0, true);
        $positiveIntNotNullValidator = v::when(v::nullValue(), v::alwaysInvalid(), $positiveIntValidator);
        $positiveIntOrNullValidator  = v::when(v::nullValue(), v::alwaysValid(), $positiveIntValidator);
        $rowIdValidator              = v::numeric()->positive();

        $types = array('CAP', 'CPC');

        $this->dataValidators['startDate']                  = v::date('Y-m-d');
        $this->dataValidators['type']                       = v::in($types);
        $this->dataValidators['statsReportId']              = $rowIdValidator;

        $this->dataValidators['reportingDate']              = v::date('Y-m-d');
        $this->dataValidators['courseId']                   = $rowIdValidator;
        $this->dataValidators['quarterStartTer']            = $positiveIntNotNullValidator;
        $this->dataValidators['quarterStartStandardStarts'] = $positiveIntNotNullValidator;
        $this->dataValidators['quarterStartXfer']           = $positiveIntNotNullValidator;
        $this->dataValidators['currentTer']                 = $positiveIntNotNullValidator;
        $this->dataValidators['currentStandardStarts']      = $positiveIntNotNullValidator;
        $this->dataValidators['currentXfer']                = $positiveIntNotNullValidator;
        $this->dataValidators['completedStandardStarts']    = $positiveIntOrNullValidator;
        $this->dataValidators['potentials']                 = $positiveIntOrNullValidator;
        $this->dataValidators['registrations']              = $positiveIntOrNullValidator;
        // Skipping center (auto-generated)
        // Skipping quarter (auto-generated)
    }

    protected function validate($data)
    {
        if (!$this->validateCourseBalance($data)) {
            $this->isValid = false;
        }
        if (!$this->validateCourseCompletionStats($data)) {
            $this->isValid = false;
        }
        if (!$this->validateCourseStartDate($data)) {
            $this->isValid = false;
        }

        return $this->isValid;
    }

    public function validateCourseCompletionStats($data)
    {
        $isValid = true;

        $statsReport = $this->getStatsReport($data->statsReportId);
        $startDate = $this->getDateObject($data->startDate);
        if ($startDate && $startDate->lt($statsReport->reportingDate)) {
            if (is_null($data->completedStandardStarts)) {
                $this->addMessage('COMMCOURSE_COMPLETED_SS_MISSING');
                $isValid = false;
            }
            if (is_null($data->potentials)) {
                $this->addMessage('COMMCOURSE_POTENTIALS_MISSING');
                $isValid = false;
            }
            if (is_null($data->registrations)) {
                $this->addMessage('COMMCOURSE_REGISTRATIONS_MISSING');
                $isValid = false;
            }

            if (!is_null($data->completedStandardStarts) && !is_null($data->currentStandardStarts)) {
                if ($data->completedStandardStarts > $data->currentStandardStarts) {

                    $this->addMessage('COMMCOURSE_COMPLETED_SS_GREATER_THAN_CURRENT_SS');
                    $isValid = false;
                } else if ($data->completedStandardStarts < ($data->currentStandardStarts - 3)) {

                    $withdrew = $data->currentStandardStarts - $data->completedStandardStarts;
                    $this->addMessage('COMMCOURSE_COMPLETED_SS_LESS_THAN_CURRENT_SS', $withdrew);
                }
            }
        }

        return $isValid;
    }

    public function validateCourseStartDate($data)
    {
        $isValid = true;

        $statsReport = $this->getStatsReport($data->statsReportId);
        $startDate = $this->getDateObject($data->startDate);
        if ($startDate && $startDate->lt($statsReport->quarter->startWeekendDate)) {
            $this->addMessage('COMMCOURSE_COURSE_DATE_BEFORE_QUARTER');
            $isValid = false;
        }

        return $isValid;
    }

    public function validateCourseBalance($data)
    {
        $isValid = true;

        if (!is_null($data->quarterStartTer)
            && !is_null($data->quarterStartStandardStarts)
            && !is_null($data->quarterStartXfer)
        ) {
            if ($data->quarterStartTer < $data->quarterStartStandardStarts) {

                $this->addMessage('COMMCOURSE_QSTART_SS_GREATER_THAN_QSTART_TER', $data->quarterStartStandardStarts, $data->quarterStartTer);
                $isValid = false;
            }
            if ($data->quarterStartTer < $data->quarterStartXfer) {

                $this->addMessage('COMMCOURSE_QSTART_XFER_GREATER_THAN_QSTART_TER', $data->quarterStartXfer, $data->quarterStartTer);
                $isValid = false;
            }
        }
        if (!is_null($data->currentTer)
            && !is_null($data->currentStandardStarts)
            && !is_null($data->currentXfer)
        ) {
            if ($data->currentTer < $data->currentStandardStarts) {

                $this->addMessage('COMMCOURSE_CURRENT_SS_GREATER_THAN_CURRENT_TER', $data->currentStandardStarts, $data->currentTer);
                $isValid = false;
            }
            if ($data->currentTer < $data->currentXfer) {

                $this->addMessage('COMMCOURSE_CURRENT_XFER_GREATER_THAN_CURRENT_TER', $data->currentXfer, $data->currentTer);
                $isValid = false;
            }
        }

        return $isValid;
    }
}
