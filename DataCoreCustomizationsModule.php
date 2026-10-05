<?php

namespace Vanderbilt\DataCoreCustomizationsModule;

class DataCoreCustomizationsModule extends \ExternalModules\AbstractExternalModule
{
	private const PROJECT_DATA_OWNER_QUESTION = "Project Data's Owner";
	private const PROJECT_DATA_OWNER_OPTIONS = [
		'vh_project' => 'VH project (VUMC employees who are using REDCap for VUMC projects and the data is owned by VUMC)',
		'collaboration' => 'Collaboration (VUMC PI with a VUMC Worktag that is working with data that belongs to an external institution)',
		'external' => 'DataCore Customer (data belongs to external institution, no VH use of the data)',
		'external_group' => 'External group (not related to VH)',
	];

	private $assemblaBillingProjects = [];
	private $redcapBillingProjects = [];

	public function redcap_every_page_top() {
		global $completed_time;

		if (($_GET['action'] ?? null) === 'create') {
			$projectDataOwnerError = $_SESSION['datacore_project_data_owner_error'] ?? null;
			unset($_SESSION['datacore_project_data_owner_error']);
			?>
			<?php if ($projectDataOwnerError !== null) { ?>
				<div class="red mb-3" role="alert"><?=htmlspecialchars($projectDataOwnerError, ENT_QUOTES, 'UTF-8')?></div>
			<?php } ?>
			<style>
				form[name="createdb"] {
					visibility: hidden;
				}
				#datacore-create-project-loading {
					align-items: center;
					display: flex;
					gap: 10px;
					margin: 24px 0;
				}
			</style>
			<div id="datacore-create-project-loading" role="status" aria-live="polite">
				<img src="<?=APP_PATH_IMAGES?>loader_simple.gif" alt="" width="32" height="32">
				<span>Loading project creation questions...</span>
			</div>
			<script>
				const projectDataOwnerOptions = <?=json_encode(self::PROJECT_DATA_OWNER_OPTIONS)?>;

				document.addEventListener('DOMContentLoaded', () => {
					const form = document.querySelector('form[name="createdb"]');
					const loading = document.getElementById('datacore-create-project-loading');

					if (form) {
						const purposeRow = form.querySelector('#row_purpose');
						if (purposeRow) {
							const questionRow = document.createElement('tr');
							questionRow.innerHTML = '<td style="padding-top:15px;width:225px;font-weight:bold;"><label for="datacore_additional_question">' + <?=json_encode(self::PROJECT_DATA_OWNER_QUESTION)?> + '</label></td>' +
								'<td style="padding-top:15px;">' +
								'<select id="datacore_additional_question" name="datacore_additional_question" class="x-form-text x-form-field" style="display:block;width:100%;max-width:700px;">' +
								'</select></td>';
							const projectDataOwnerSelect = questionRow.querySelector('#datacore_additional_question');
							const emptyOption = document.createElement('option');
							emptyOption.value = '';
							emptyOption.textContent = '-- Please select --';
							projectDataOwnerSelect.append(emptyOption);

							for (const [value, label] of Object.entries(projectDataOwnerOptions)) {
								const option = document.createElement('option');
								option.value = value;
								option.textContent = label;
								projectDataOwnerSelect.append(option);
							}

							purposeRow.after(questionRow);
						}

						form.style.visibility = 'visible';
					}

					loading?.remove();
				});
			</script>
			<?php
		}

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

	public function redcap_every_page_before_render($projectId) {
		if (!defined('PAGE') || PAGE !== 'ProjectGeneral/create_project.php' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
			return;
		}

		if (!$this->isProjectDataOwnerRequired()) {
			return;
		}

		$projectDataOwner = $_POST['datacore_additional_question'] ?? null;
		if (is_string($projectDataOwner) && isset(self::PROJECT_DATA_OWNER_OPTIONS[$projectDataOwner])) {
			return;
		}

		$_SESSION['datacore_project_data_owner_error'] = 'Please provide an answer to: ' . self::PROJECT_DATA_OWNER_QUESTION;
		redirect(APP_PATH_WEBROOT_PARENT . 'index.php?action=create');
	}

	private function isProjectDataOwnerRequired() {
		$testModeDisabled = $this->getSystemSetting('disable-project-type-test-mode') == 1;
		if ($testModeDisabled) {
			return true;
		}
		$currentUsername = strtolower(trim((string) $this->getUsername()));

		$testUsers = $this->getTestUsers();
		return $currentUsername !== '' && in_array($currentUsername, $testUsers, true);
	}

	private function getTestUsers() {
		$testUsersSetting = (string) ($this->getSystemSetting('project-type-test-users') ?? '');
		$testUsers = preg_split('/[\r\n,]+/', $testUsersSetting, -1, PREG_SPLIT_NO_EMPTY);
		$testUsers = array_map(function ($username) {
			return strtolower(trim($username));
		}, $testUsers);
		return array_filter($testUsers);
	}

	public function redcap_module_project_save_after($projectId, $msgFlag, $projectTitle, $userId) {
		if ($msgFlag !== 'newproject') {
			return;
		}

		$projectDataOwner = $_POST['datacore_additional_question'] ?? '';
		if (!isset(self::PROJECT_DATA_OWNER_OPTIONS[$projectDataOwner])) {
			return;
		}

		$this->setProjectSetting('project-data-owner', $projectDataOwner, $projectId);
		$this->log('Project data owner selected', [
			'project_id' => (int) $projectId,
			'project_data_owner' => $projectDataOwner,
			'project_data_owner_label' => self::PROJECT_DATA_OWNER_OPTIONS[$projectDataOwner],
		]);
	}

	public function getProjectListPIDs() {
		$pids = $this->getSystemSetting('project-list-pid');
		if (!is_array($pids)) {
			$pids = [];
		}

		$newPids = [];
		foreach ($pids as $pid) {
			$newPids[] = (int) $pid;
		}

		return $newPids;
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
		$projectListPids = $this->getProjectListPIDs();
		if (empty($projectListPids)) {
			// This setting has not been set
			return;
		}

		$enabledProjects = array_flip($this->getProjectsWithModuleEnabledCustom());
		foreach ($projectListPids as $projectListPid) {
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
		}

		// Any projects NOT in the list should NOT have the module enabled.
		foreach ($enabledProjects as $pid => $unused) {
			$this->disableModule($pid);
		}
	}

	public function redcap_save_record($pid, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
		$projectListPids = $this->getProjectListPIDs();
		$targetPid = (int) ($_POST['pid'] ?? 0);
		if (in_array($pid, $projectListPids) && $instrument === 'project_creation_tracking' && $targetPid !== 0) {
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
		if ($link['name'] === 'Download DataCore Project List' && !in_array($project_id, $this->getProjectListPIDs())) {
			return false;
		}

		return $link;
	}
}
