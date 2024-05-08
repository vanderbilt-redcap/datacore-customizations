<?php namespace Vanderbilt\DataCoreCustomizationsModule;

class DataCoreCustomizationsModule extends \ExternalModules\AbstractExternalModule
{
    private $assemblaBillingProjects = [];
    private $redcapBillingProjects = [];

    function redcap_every_page_top(){
        global $completed_time;

        $GLOBALS['lang']['bottom_93'] = $this->getSystemSetting('completed-dialog-message');

        if(PAGE === 'ProjectSetup/other_functionality.php'){
            ?>
            <script>
                (() => {
                    if(<?=json_encode($this->isSuperUser())?>){
                        return;
                    }

                    const start = Date.now();
                    const intervalId = setInterval(() => {
                        const elapsed = Date.now() - start
                        const message = <?=json_encode($this->getProjectSetting('status-transition-blocked-message'))?>;
                        const methodOverrides = {}

                        if(<?=json_encode($completed_time == '')?>){
                            methodOverrides['markProjectAsCompleted'] = (original) => {
                                simpleDialog(message)
                            }
                        }

                        methodOverrides['btnMoveToProd'] = (original) => {
                            if(<?=json_encode($this->getProjectStatus() === 'DEV')?>){
                                original()
                            }
                            else{
                                simpleDialog(message)
                            }
                        }

                        methodOverrides['delete_project'] = (original) => {
                            simpleDialog(message)
                        }

                        if(window[Object.keys(methodOverrides)[0]] !== undefined || elapsed > 3000){
                            clearInterval(intervalId)

                            for(const [name, action] of Object.entries(methodOverrides)){
                                const original = window[name]
                                if(original === undefined){
                                    alert('The DataCore Customizations module could not find the ' + name + '() function!')
                                    continue
                                }

                                window[name] = (...args) => action(() => {
                                    original(...args)
                                })
                            }
                        }
                    }, 100)
                })()
            </script>
            <?php
        }
    }

    function getProjectListPID(){
        return (int) $this->getSystemSetting('project-list-pid');
    }

    function getProjectsWithModuleEnabledCustom(){
        $results = $this->query("
            SELECT CAST(s.project_id AS CHAR) AS project_id
            FROM redcap_external_modules m
            JOIN redcap_external_module_settings s
                ON m.external_module_id = s.external_module_id
            JOIN redcap_projects p
                ON s.project_id = p.project_id
            WHERE
                m.directory_prefix = ?
                AND s.value = 'true'
                AND s.key = 'enabled'
        ", $this->getPrefix());

        $pids = [];
        while($row = $results->fetch_assoc()) {
            $pids[] = $row['project_id'];
        }

        return $pids;
    }

    function dailyCron(){
        $projectListPid = $this->getProjectListPID();
        if($projectListPid === 0){
            // This setting has not been set
            return;
        }

        $enabledProjects = array_flip($this->getProjectsWithModuleEnabledCustom());
        $records = \REDCap::getData($projectListPid, 'json-array', null, 'pid');
        $records[] = ['pid' => $projectListPid];
        foreach($records as $record){
            $pid = (int) trim($record['pid']);
            if($pid === 0){
                continue;
            }

            if(isset($enabledProjects[$pid])){
                unset($enabledProjects[$pid]);
            }
            else{
                $result = $this->query('select project_id from redcap_projects where project_id = ?', $pid);
                if($result->fetch_assoc() === null){
                    // The specified project has likely been deleted.  Ignore it.
                }
                else{
                    $this->enableModule($pid);
                }
            }
        }

        // Any projects NOT in the list should NOT have the module enabled.
        foreach($enabledProjects as $pid=>$unused){
            $this->disableModule($pid);
        }
    }

    function redcap_save_record(int $pid, string $record = NULL, string $instrument, int $event_id, int $group_id = NULL, string $survey_hash = NULL, int $response_id = NULL, int $repeat_instance = 1){
        $projectListPid = $this->getProjectListPID();
        $targetPid = (int) $_POST['pid'];
        if($pid === $projectListPid && $instrument === 'project_creation_tracking' && $targetPid !== 0){
            $this->enableModule($targetPid);
        }
    }

    function arrayDeepDiff($a, $b){
        $diff = array_udiff($a, $b,  function($c, $d){
            ksort($c);
            ksort($d);

            return strcmp(
                json_encode($c),
                json_encode($d),
            );
        });

        // Make sure keys start with zero and are sequential
        return array_values($diff);
    }

    function hasRequestedBy($s){
        return str_contains($s, 'Requested by');
    }

    function getRequestedByError(){
        return 'Please ask Kelsey or Lindsay to enter the "Requested By"field in Assembla for the following tickets, then try again:';
    }

    function getHoursError($log){
        return 'Time entries that include both "project_hours" and "project_hours_2" are not currently supported: ' . json_encode($log);
    }

    function getProjectNameError(){
        return '
            Please ask Kelsey or Lindsay to enter the "Hours Survey Project" field in Assembla for the following tickets, then try again.<br>
            This message may also display if the "Hours Survey Project" needs to be updated because the code or label changed in the hours survey:
        ';
    }

    function checkForErrors($log){
        $hours1 = $log['project_hours'] ?? null;
        $hours2 = $log['project_hours_2'] ?? null;
        $notes1 = $log['project_notes'] ?? null;
        $notes2 = $log['project_notes_2'] ?? null;
        $projectCode = $log['project_name_2'] ?? null;

        if(!empty($hours1) && !empty($hours2)){
            throw new \Exception($this->getHoursError($log));
        }
        else if(
            (!empty($hours1) && !$this->hasRequestedBy($notes1))
            ||
            (!empty($hours2) && !$this->hasRequestedBy($notes2))
        ){
            return $this->getRequestedByError();
        }
        else if(
            empty($projectCode)
            ||
            $this->getAssemblaBillingProject($projectCode) !== $this->getREDCapBillingProject($projectCode)
        ){
            return $this->getProjectNameError();
        }
        else if(!is_numeric($log['project_role'])){
            /**
             * This check is mainly to cover the scenario where someone accidentally
             * has the Assembla customizations disabled, and time entries that should
             * have had roles are missing them.
             */
            return $this->getMissingRoleError();
        }

        return null;
    }

    function getMissingRoleError(){
        return '
            A role has not been selected for some of the time entries on the following tickets.
            Please edit them and make sure a role is selected.
            You can see which time entries are missing a role via the "Download as CSV" button:
        ';
    }

    function ensureUniqueCheckFieldsExist($logs){
        foreach($this->getUniqueCheckFields() as $field){
            foreach($logs as $log){
                if(empty($log[$field])){
                    throw new \Exception("The following log cannot be processed because it is missing the '$field' field: " . json_encode($log));
                }
            }
        }
    }

    function compareTimeLogs($assemblaLogs, $existingLogs){
        foreach(func_get_args() as $logs){
            $this->ensureUniqueCheckFieldsExist($logs);
        }

        $unmatched = $this->arrayDeepDiff($existingLogs, $assemblaLogs);
        if(!empty($unmatched)){
            return [$unmatched, [], []];
        }

        $new = [];
        $incomplete = [];
        foreach($this->arrayDeepDiff($assemblaLogs, $existingLogs) as $newLog){
            $error = $this->checkForErrors($newLog);
            if($error === null){
                $new[] = $newLog;
            }
            else{
                $incomplete[$error][] = $newLog;
            }
        }

        return [[], $new, $incomplete];
    }

    function displayTimeLogs($message, $logs){
        if(empty($logs)){
            return;
        }

        echo "<h6>$message</h6>";
        echo "<table class='table'>";
        echo "<tr>";
        echo "<th>Hours</th>";
        echo "<th>Description</th>";
        echo "</tr>";

        foreach($logs as $log){
            $hours = $log['project_hours'];
            $notes = $log['project_notes'];
            if($hours === ''){
                $hours = $log['project_hours_2'];
                $notes = $log['project_notes_2'];
            }

            echo "<tr>";
            echo "<td>$hours</td>";
            echo "<td>$notes</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

    private function getTicketNumber($log){
        $notes = $log['project_notes'];
        if(empty($notes)){
            $notes = $log['project_notes_2'];
        }

        $parts = explode(':', $notes);
        $number = ltrim($parts[0], '#');

        if(empty($number)){
            throw new \Exception("Could not parse ticket number: " . json_encode($log));
        }

        return $number;
    }

    function getTicketLinks($logs){
        $numbers = [];
        foreach($logs as $log){
            $numbers[$this->getTicketNumber($log)] = true;
        }

        $links = [];
        foreach(array_keys($numbers) as $number){
            $url = "https://app.assembla.com/spaces/sdtest/tickets/$number";
            $links[] = "<li><a href='$url'>$url</a></li>";
        }

        return '<ul>' . implode("\n", $links) . '</ul>';
    }

    function getUniqueCheckFields(){
        return [
            'programmer_name',
            'billing_month',
            'billing_year',
            'project_role',
        ];
    }

    function getProgrammerId($pid){
        $programmerName = $GLOBALS['user_lastname'] . ' (' . $GLOBALS['user_firstname'] . ')';
        $programmerId = array_flip($this->getChoiceLabels('programmer_name', $pid))[$programmerName];
        if(empty($programmerId)){
            die("The following name could not be found as an option in the hours survey: $programmerName");
        }

        return $programmerId;
    }

    function parseHoursSurveyProjectId($hoursSurveyProject){
        $parts = explode('(', $hoursSurveyProject);
        if(count($parts) < 2){
            return '';
        }

        $numberPortion = array_pop($parts);
        $label = trim(implode('(', $parts));

        $parts = explode(')', $numberPortion);
        if(count($parts) < 2){
            return '';
        }

        $value = $parts[0];

        if(!ctype_digit($value)){
            return '';
        }

        $this->setAssemblaBillingProject($value, $label);
    
        return $value;
    }

    function setAssemblaBillingProject($code, $label){
        $this->assemblaBillingProjects[$code] = $label;
    }

    function getAssemblaBillingProject($code){
        $value = $this->assemblaBillingProjects[$code] ?? null;
        if($value === null){
            throw new \Exception("Assembla billing project $code could not be found!");
        }

        return $value;
    }

    function setREDCapBillingProject($code, $label){
        $this->redcapBillingProjects[$code] = $label;
    }

    function getREDCapBillingProjects(){
        if(empty($this->redcapBillingProjects)){
            $pid = $this->getSystemSetting('hours-survey-pid');
            $project = new \Project($pid);
            $sql = $project->metadata['project_name_2']['element_enum'];

            foreach($this->query($sql, [])->fetch_all() as $project){
                $labelParts = explode(',', $project[1]);
                array_pop($labelParts); // Remove the cost center, since they change more often than we'd like to re-select this value in Assembla.
                $label = trim(implode(',', $labelParts));

                $this->setREDCapBillingProject($project[0], $label);
            }
        }

        return $this->redcapBillingProjects;
    }

    function getREDCapBillingProject($projectCode){
        return $this->getREDCapBillingProjects()[$projectCode] ?? 'REDCap Billing Project Not Found';
    }

    function redcap_module_link_check_display($project_id, $link){
        if($link['name'] === 'Download DataCore Project List' && $project_id != $this->getProjectListPID()){
            return false;
        }

        return $link;
    }
}