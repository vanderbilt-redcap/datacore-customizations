<?php namespace Vanderbilt\DataCoreCustomizationsModule;

class DataCoreCustomizationsModule extends \ExternalModules\AbstractExternalModule
{
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

    private function getProjectListPID(){
        return (int) $this->getSystemSetting('project-list-pid');
    }

    function dailyCron(){
        $enabledProjects = array_flip($this->getProjectsWithModuleEnabled());
        $projectListPid = $this->getProjectListPID();
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
                $this->enableModule($pid);
            }
        }

        // Any projects NOT in the list should NOT have the module enabled.
        foreach($enabledProjects as $pid=>$unused){
            $this->disableModule($pid);
        }
    }
}