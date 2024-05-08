<?php

$records = \REDCap::getData($module->getProjectListPID(), 'json-array', null, 'pid');
$pids = array_column($records, 'pid');

$query = $module->createQuery();
$query->add('
    select
        project_id,
        app_title,
        status,
        completed_time
    from redcap_projects
    where
')->addInClause('project_id', $pids);

$result = $query->execute();

header('Content-disposition: attachment; filename="DataCore Project List.csv"'); 

$out = fopen('php://output', 'w');
echo "\xEF\xBB\xBF"; // UTF-8 BOM required for correct UTF-8 character handling
fputcsv($out, ['Project ID', 'Name', 'Status', 'Completed']);
while($row = $result->fetch_assoc()){
    $row['app_title'] = html_entity_decode($row['app_title'], ENT_QUOTES);

    $status = $row['status'];
    if($status == '0'){
        $statusLabel = "Development";
    }
    elseif($status == '1'){
        $statusLabel = "Production";
    }
    elseif($status == '2'){
        $statusLabel = "Analysis/Cleanup";
    }
    else{
        $statusLabel = "Unknown";
    }
    $row['status'] = $statusLabel;

    $row['completed_time'] = $row['completed_time'] === null ? 'No' : 'Yes';

    fputcsv($out, $row);
}