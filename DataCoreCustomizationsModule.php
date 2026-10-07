<?php

namespace Vanderbilt\DataCoreCustomizationsModule;

class DataCoreCustomizationsModule extends \ExternalModules\AbstractExternalModule
{
	public const PROJECT_DATA_OWNER_QUESTION = "Project Data's Owner";
	public const PROJECT_DATA_OWNER_OPTIONS = [
		'vh_project' => 'VH project (VUMC employees who are using REDCap for VUMC projects and the data is owned by VUMC)',
		'collaboration' => 'Collaboration (VUMC PI with a VUMC Worktag that is working with data that belongs to an external institution)',
		'external' => 'DataCore Customer (data belongs to external institution, no VH use of the data)',
		'external_group' => 'External group (not related to VH)',
	];

	private $assemblaBillingProjects = [];
	private $redcapBillingProjects = [];

	public function redcap_every_page_top() {
		global $completed_time;

		$projectId = defined('PROJECT_ID') ? (int) PROJECT_ID : 0;
		if ($projectId > 0 && $this->isSuperUser()) {
			$this->printProjectDetailsButton($projectId);
		}

		if (($_GET['action'] ?? null) === 'create') {
			$projectDataOwnerError = $_SESSION['datacore_project_data_owner_error'] ?? null;
			unset($_SESSION['datacore_project_data_owner_error']);
			$showQuestion = $this->isProjectDataOwnerRequired();
			if($showQuestion) {
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
				const projectDataOwnerQuestion = <?=json_encode(self::PROJECT_DATA_OWNER_QUESTION)?>;

				document.addEventListener('DOMContentLoaded', () => {
					const form = document.querySelector('form[name="createdb"]');
					const loading = document.getElementById('datacore-create-project-loading');

					if (form) {
						const purposeRow = form.querySelector('#row_purpose');
						if (purposeRow) {
							const questionRow = document.createElement('tr');
							questionRow.innerHTML = '<td style="padding-top:15px;width:225px;font-weight:bold;"><label for="datacore_additional_question">' + projectDataOwnerQuestion + '</label></td>' +
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

							const clearProjectDataOwnerError = () => {
								if (Object.prototype.hasOwnProperty.call(projectDataOwnerOptions, projectDataOwnerSelect.value)) {
									projectDataOwnerSelect.removeAttribute('aria-invalid');
								}
							};
							projectDataOwnerSelect.addEventListener('change', clearProjectDataOwnerError);
							const hasValidProjectDataOwner = () => Object.prototype.hasOwnProperty.call(projectDataOwnerOptions, projectDataOwnerSelect.value);
							const showProjectDataOwnerError = () => {
								projectDataOwnerSelect.setAttribute('aria-invalid', 'true');
								simpleDialog(
									'Please provide an answer to: ' + projectDataOwnerQuestion,
									window.lang.create_project_144,
									null,
									null,
									() => projectDataOwnerSelect.focus()
								);
							};
							form.addEventListener('submit', (event) => {
								if (!hasValidProjectDataOwner()) {
									event.preventDefault();
									showProjectDataOwnerError();
								}
							});

							const submitForm = HTMLFormElement.prototype.submit.bind(form);
							form.submit = () => {
								if (!hasValidProjectDataOwner()) {
									if (typeof showProgress === 'function') {
										showProgress(0, 0);
									}
									showProjectDataOwnerError();
									return;
								}

								submitForm();
							};

							purposeRow.after(questionRow);
						}

						form.style.visibility = 'visible';
					}

					loading?.remove();
				});
			</script>
			<?php
			}
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

	private function printProjectDetailsButton($projectId) {
		$projectDetails = $this->getProjectDataOwnerDetails($projectId);
		if ($projectDetails !== null) {
			?>
			<style>
				#datacore-project-data-owner-button {
					margin: 8px;
					text-align: left;
					width: calc(100% - 16px);
				}
				dialog.datacore-project-details-dialog {
					border: 1px solid #aaa;
					border-radius: 4px;
					max-width: min(640px, calc(100vw - 32px));
					padding: 24px;
					width: 100%;
				}
				dialog.datacore-project-details-dialog::backdrop {
					background: rgba(0, 0, 0, 0.45);
				}
				.datacore-project-details-list {
					display: grid;
					gap: 8px 16px;
					grid-template-columns: minmax(120px, 1fr) 2fr;
					margin: 16px 0;
				}
				.datacore-project-details-list dt {
					font-weight: bold;
				}
				.datacore-project-details-list dd {
					margin: 0;
					overflow-wrap: anywhere;
					white-space: pre-wrap;
				}
				.datacore-project-details-actions {
					text-align: right;
				}
			</style>
			<script>
				(() => {
					const details = <?=json_encode($projectDetails, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
					const addProjectDetailsButton = () => {
						const sidebar = document.getElementById('west');
						if (!sidebar || document.getElementById('datacore-project-data-owner-button')) {
							return;
						}

						const button = document.createElement('button');
						button.id = 'datacore-project-data-owner-button';
						button.type = 'button';
						button.className = 'btn btn-link';
						button.textContent = 'Project Data\'s Owner Details';

						const dialog = document.createElement('dialog');
						dialog.className = 'datacore-project-details-dialog';
						dialog.setAttribute('aria-labelledby', 'datacore-project-details-title');

						const heading = document.createElement('h2');
						heading.id = 'datacore-project-details-title';
						heading.textContent = 'Project details';
						dialog.append(heading);

						const detailList = document.createElement('dl');
						detailList.className = 'datacore-project-details-list';
						for (const [label, value] of Object.entries(details)) {
							const term = document.createElement('dt');
							term.textContent = label;
							const description = document.createElement('dd');
							description.textContent = value || 'Not available';
							detailList.append(term, description);
						}
						dialog.append(detailList);

						const actions = document.createElement('div');
						actions.className = 'datacore-project-details-actions';
						const closeButton = document.createElement('button');
						closeButton.type = 'button';
						closeButton.className = 'btn btn-secondary';
						closeButton.textContent = 'Close';
						closeButton.addEventListener('click', () => dialog.close());
						actions.append(closeButton);
						dialog.append(actions);
						dialog.addEventListener('click', (event) => {
							if (event.target === dialog) {
								dialog.close();
							}
						});

						document.body.append(dialog);
						button.addEventListener('click', () => dialog.showModal());
						sidebar.append(button);
					};

					if (document.readyState === 'loading') {
						document.addEventListener('DOMContentLoaded', addProjectDetailsButton, {once: true});
					} else {
						addProjectDetailsButton();
					}
				})();
			</script>
			<?php
		}
	}

	private function getProjectDataOwnerDetails($projectId) {
		$result = $this->query(
			' SELECT app_title, purpose, purpose_other, project_note, creation_time, created_by
			  FROM redcap_projects
			  WHERE project_id = ?',
			$projectId
		);
		$project = $result->fetch_assoc();
		if (!$project) {
			return null;
		}

		global $lang;
		$purposeLabels = [
			\RedCapDB::PURPOSE_PRACTICE => $lang['create_project_15'],
			\RedCapDB::PURPOSE_OPS => $lang['create_project_16'],
			\RedCapDB::PURPOSE_RESEARCH => $lang['create_project_17'],
			\RedCapDB::PURPOSE_QUALITY => $lang['create_project_18'],
			\RedCapDB::PURPOSE_OTHER => $lang['create_project_19'],
		];
		$purpose = $purposeLabels[$project['purpose']] ?? 'Not available';
		if ((int) $project['purpose'] === \RedCapDB::PURPOSE_OTHER && $project['purpose_other'] !== '') {
			$purpose .= ': ' . $project['purpose_other'];
		}

		$creatorInfo = \User::getUserInfoByUiid($project['created_by']);
		$creator = $creatorInfo ? trim($creatorInfo['user_firstname'] . ' ' . $creatorInfo['user_lastname']) : '';
		if ($creator !== '' && !empty($creatorInfo['username'])) {
			$creator .= ' (' . $creatorInfo['username'] . ')';
		} elseif ($creator === '') {
			$creator = $creatorInfo['username'] ?? 'Not available';
		}

		$projectDataOwner = $this->getProjectSetting('project-data-owner', $projectId);
		return [
			"Project Data's Owner" => self::PROJECT_DATA_OWNER_OPTIONS[$projectDataOwner] ?? 'No answer recorded',
			'Project title' => $project['app_title'],
			'Purpose' => $purpose,
			'Creator' => $creator,
			'Creation date' => $project['creation_time'] ? \DateTimeRC::format_user_datetime($project['creation_time'], 'Y-M-D_24') : 'Not available',
			'Project notes' => $project['project_note'] ?: 'No project notes',
		];
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
		$currentUsername = strtolower(trim($this->getUser()->getUsername()));

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
		if ($link['name'] === 'Project Data Owner Report' && !$this->isSuperUser()) {
			return null;
		}

		if ($link['name'] === 'Download DataCore Project List' && !in_array($project_id, $this->getProjectListPIDs())) {
			return false;
		}

		return $link;
	}
}
