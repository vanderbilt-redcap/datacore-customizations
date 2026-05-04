<?php

namespace Vanderbilt\DataCoreCustomizationsModule;

class DataCoreCustomizationsModule extends \ExternalModules\AbstractExternalModule
{
	private $assemblaBillingProjects = [];
	private $redcapBillingProjects = [];

	public function redcap_every_page_top() {
		global $completed_time;

		$GLOBALS['lang']['bottom_93'] = $this->getSystemSetting('completed-dialog-message');

		if (PAGE === 'ProjectSetup/other_functionality.php') {
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

	public function getProjectListPID() {
		return (int) $this->getSystemSetting('project-list-pid');
	}

	public function getProjectsWithModuleEnabledCustom() {
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
		while ($row = $results->fetch_assoc()) {
			$pids[] = $row['project_id'];
		}

		return $pids;
	}

	public function dailyCron() {
		$projectListPid = $this->getProjectListPID();
		if ($projectListPid === 0) {
			// This setting has not been set
			return;
		}

		$enabledProjects = array_flip($this->getProjectsWithModuleEnabledCustom());
		$records = \REDCap::getData($projectListPid, 'json-array', null, 'pid');
		$records[] = ['pid' => $projectListPid];
		foreach ($records as $record) {
			$pid = (int) trim($record['pid']);
			if ($pid === 0) {
				continue;
			}

			if (isset($enabledProjects[$pid])) {
				unset($enabledProjects[$pid]);
			} else {
				$result = $this->query('select project_id from redcap_projects where project_id = ?', $pid);
				if ($result->fetch_assoc() === null) {
					// The specified project has likely been deleted.  Ignore it.
				} else {
					$this->enableModule($pid);
				}
			}
		}

		// Any projects NOT in the list should NOT have the module enabled.
		foreach ($enabledProjects as $pid => $unused) {
			$this->disableModule($pid);
		}
	}

	public function redcap_save_record($pid, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
		$projectListPid = $this->getProjectListPID();
		$targetPid = (int) ($_POST['pid'] ?? 0);
		if ($pid === $projectListPid && $instrument === 'project_creation_tracking' && $targetPid !== 0) {
			$this->enableModule($targetPid);
		}
	}


	public function setAssemblaBillingProject($code, $label) {
		$this->assemblaBillingProjects[$code] = $label;
	}

	public function getAssemblaBillingProject($code) {
		$value = $this->assemblaBillingProjects[$code] ?? null;
		if ($value === null) {
			throw new \Exception("Assembla billing project $code could not be found!");
		}

		return $value;
	}

	public function setREDCapBillingProject($code, $label) {
		$this->redcapBillingProjects[$code] = $label;
	}

	public function getREDCapBillingProjects() {
		if (empty($this->redcapBillingProjects)) {
			$pid = $this->getSystemSetting('hours-survey-pid');
			$project = new \Project($pid);
			$sql = $project->metadata['project_name_2']['element_enum'];

			foreach ($this->query($sql, [])->fetch_all() as $project) {
				$labelParts = explode(',', $project[1]);
				array_pop($labelParts); // Remove the cost center, since they change more often than we'd like to re-select this value in Assembla.
				$label = trim(implode(',', $labelParts));

				$this->setREDCapBillingProject($project[0], $label);
			}
		}

		return $this->redcapBillingProjects;
	}

	public function getREDCapBillingProject($projectCode) {
		return $this->getREDCapBillingProjects()[$projectCode] ?? 'REDCap Billing Project Not Found';
	}

	public function redcap_module_link_check_display($project_id, $link) {
		if ($link['name'] === 'Download DataCore Project List' && $project_id != $this->getProjectListPID()) {
			return false;
		}

		return $link;
	}
}
