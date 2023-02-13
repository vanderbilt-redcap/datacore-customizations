<?php

$records = json_decode($_POST['data'], true);

foreach($records as &$record){
    $record['record_id'] = -1;
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
