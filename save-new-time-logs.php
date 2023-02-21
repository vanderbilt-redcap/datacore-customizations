<?php

$records = json_decode($_POST['data'], true);

/**
 * Record IDs should be ignored since 'addingAutoNumberedRecords' is true,
 * but let's use negative record IDs just in case, so they can't possibly overlap with any actual entries.
 */
$nextRecordId = -1;

foreach($records as &$record){
    $record['record_id'] = $nextRecordId--;
    $record['today_date'] = date('Y-m-d');
}

$result = REDCap::saveData([
    'project_id' => $module->getSystemSetting('hours-survey-pid'),
    'addingAutoNumberedRecords' => true,
    'data' => $records,
    'dataFormat' => 'json-array',
]);

if($result['errors'] !== [] || $result['warnings'] !== []){
    var_dump($result);
}
else{
    echo 'success';
}
