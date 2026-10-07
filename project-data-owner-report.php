<?php

if (!$module->isSuperUser()) {
	http_response_code(403);
	exit('Superuser access is required.');
}

$answerOptions = $module::PROJECT_DATA_OWNER_OPTIONS;

$getString = function ($key) {
	return isset($_GET[$key]) && is_string($_GET[$key]) ? trim($_GET[$key]) : '';
};
$search = $getString('search');
$answer = $getString('answer');
if (!array_key_exists($answer, $answerOptions)) {
	$answer = '';
}
$sortColumns = [
	'project_id' => 'p.project_id',
	'title' => 'p.app_title',
	'answer' => 's.value',
	'purpose' => 'p.purpose',
	'creator' => 'u.user_lastname',
	'created' => 'p.creation_time',
	'notes' => 'p.project_note',
];
$sort = $getString('sort');
if (!isset($sortColumns[$sort])) {
	$sort = 'created';
}
$direction = strtolower($getString('direction')) === 'asc' ? 'ASC' : 'DESC';
$page = max(1, (int) $getString('page'));
$pageSize = 50;

$where = "
	FROM redcap_external_modules m
	JOIN redcap_external_module_settings s
		ON s.external_module_id = m.external_module_id
	JOIN redcap_projects p
		ON p.project_id = s.project_id
	LEFT JOIN redcap_user_information u
		ON u.ui_id = p.created_by
	WHERE m.directory_prefix = ?
		AND s.key = ?
		AND s.value IS NOT NULL
		AND s.value <> ''
";
$parameters = [$module->getPrefix(), 'project-data-owner'];

if ($answer !== '') {
	$where .= ' AND s.value = ?';
	$parameters[] = $answer;
}
if ($search !== '') {
	$searchPattern = '%' . $search . '%';
	$where .= " AND (
		CAST(p.project_id AS CHAR) LIKE ?
		OR p.app_title LIKE ?
		OR s.value LIKE ?
		OR CAST(p.purpose AS CHAR) LIKE ?
		OR p.purpose_other LIKE ?
		OR u.username LIKE ?
		OR u.user_firstname LIKE ?
		OR u.user_lastname LIKE ?
		OR DATE_FORMAT(p.creation_time, '%Y-%m-%d') LIKE ?
		OR p.project_note LIKE ?
	)";
	$parameters = array_merge($parameters, array_fill(0, 10, $searchPattern));
}

$countResult = $module->query('SELECT COUNT(*) AS total ' . $where, $parameters);
$totalRows = (int) $countResult->fetch_assoc()['total'];
$totalPages = max(1, (int) ceil($totalRows / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;
$orderBy = $sortColumns[$sort] . ' ' . $direction;
if ($sort === 'creator') {
	$orderBy .= ', u.user_firstname ' . $direction;
}
$orderBy .= ', p.project_id DESC';

$select = "
	SELECT p.project_id, p.app_title, s.value AS owner_answer, p.purpose,
		p.purpose_other, p.creation_time, p.project_note, u.username,
		u.user_firstname, u.user_lastname
	" . $where . "
	ORDER BY $orderBy
";
$fetchRows = function ($limit = null) use ($module, $select, $parameters, $pageSize, $offset) {
	$sql = $select;
	if ($limit !== null) {
		$sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
	}
	$result = $module->query($sql, $parameters);
	$rows = [];
	while ($row = $result->fetch_assoc()) {
		$rows[] = $row;
	}
	return $rows;
};
$rows = $fetchRows($pageSize);

$purposeLabels = [
	0 => $lang['create_project_15'] ?? 'Practice / Just for fun',
	4 => $lang['create_project_16'] ?? 'Operational Support',
	2 => $lang['create_project_17'] ?? 'Research',
	3 => $lang['create_project_18'] ?? 'Quality Improvement',
	1 => $lang['create_project_19'] ?? 'Other',
];
$formatRow = function ($row) use ($answerOptions, $purposeLabels) {
	$purpose = $purposeLabels[$row['purpose']] ?? 'Not available';
	if ((int) $row['purpose'] === 1 && $row['purpose_other'] !== '') {
		$purpose .= ': ' . $row['purpose_other'];
	}
	$creator = trim(($row['user_firstname'] ?? '') . ' ' . ($row['user_lastname'] ?? ''));
	if ($creator !== '' && !empty($row['username'])) {
		$creator .= ' (' . $row['username'] . ')';
	} elseif ($creator === '') {
		$creator = $row['username'] ?? '';
	}
	return [
		'Project ID' => (string) $row['project_id'],
		'Project title' => $row['app_title'],
		"Project Data's Owner" => $answerOptions[$row['owner_answer']] ?? $row['owner_answer'],
		'Purpose' => $purpose,
		'Creator' => $creator,
		'Creation date' => $row['creation_time'] ? \DateTimeRC::format_user_datetime($row['creation_time'], 'Y-M-D_24') : '',
		'Project notes' => $row['project_note'] ?? '',
	];
};

$baseUrl = $module->getUrl('project-data-owner-report.php');
$buildUrl = function ($changes = []) use ($baseUrl, $search, $answer, $sort, $direction, $page) {
	$query = array_merge([
		'search' => $search,
		'answer' => $answer,
		'sort' => $sort,
		'direction' => strtolower($direction),
		'page' => $page,
	], $changes);
	return $baseUrl . (strpos($baseUrl, '?') === false ? '?' : '&') . http_build_query($query);
};

if ($getString('export') === 'csv') {
	$csvRows = $fetchRows();
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="project-data-owner-report.csv"');
	header('Cache-Control: no-store, no-cache, must-revalidate');
	$output = fopen('php://output', 'w');
	$headers = ['Project ID', 'Project title', "Project Data's Owner", 'Purpose', 'Creator', 'Creation date', 'Project notes'];
	fputcsv($output, $headers);
	foreach ($csvRows as $row) {
		$values = array_values($formatRow($row));
		foreach ($values as &$value) {
			$value = (string) $value;
			if (preg_match('/^[\x00-\x20]*[=+\-@]/', $value)) {
				$value = "'" . $value;
			}
		}
		unset($value);
		fputcsv($output, $values);
	}
	fclose($output);
	exit;
}

$escape = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$displayRows = array_map($formatRow, $rows);
$columnSorts = [
	'Project ID' => 'project_id',
	'Project title' => 'title',
	"Project Data's Owner" => 'answer',
	'Purpose' => 'purpose',
	'Creator' => 'creator',
	'Creation date' => 'created',
	'Project notes' => 'notes',
];
$exportUrl = $buildUrl(['export' => 'csv', 'page' => 1]);

require_once APP_PATH_DOCROOT . 'ControlCenter/header.php';
?>
<style>
	.project-data-owner-filters {
		align-items: end;
		display: flex;
		flex-wrap: wrap;
		gap: 12px;
		margin: 16px 0;
	}
	.project-data-owner-filters label {
		display: grid;
		gap: 4px;
	}
	.project-data-owner-table-wrap {
		overflow-x: auto;
	}
	.project-data-owner-table {
		min-width: 1000px;
		width: 100%;
	}
	.project-data-owner-table th,
	.project-data-owner-table td {
		padding: 7px;
		vertical-align: top;
	}
	.project-data-owner-table td:last-child {
		min-width: 220px;
		white-space: pre-wrap;
	}
	.project-data-owner-pagination {
		align-items: center;
		display: flex;
		gap: 12px;
		margin: 16px 0;
	}
</style>
<div class="projhdr">Project Data Owner Report</div>
<p><?= $totalRows ?> project<?= $totalRows === 1 ? '' : 's' ?> with a recorded answer.</p>
<form class="project-data-owner-filters" action="<?= $escape($baseUrl) ?>" method="get">
	<label>
		Search
		<input class="x-form-text x-form-field" type="search" name="search" value="<?= $escape($search) ?>">
	</label>
	<label>
		Project Data's Owner
		<select class="x-form-text x-form-field" name="answer">
			<option value="">All answers</option>
			<?php foreach ($answerOptions as $value => $label) { ?>
				<option value="<?= $escape($value) ?>" <?= $answer === $value ? 'selected' : '' ?>><?= $escape($label) ?></option>
			<?php } ?>
		</select>
	</label>
	<button class="btn btn-primaryrc" type="submit">Filter</button>
	<a class="btn btn-defaultrc" href="<?= $escape($baseUrl) ?>">Clear</a>
	<a class="btn btn-defaultrc" href="<?= $escape($exportUrl) ?>">Export filtered CSV</a>
</form>
<div class="project-data-owner-table-wrap">
	<table class="table table-striped table-bordered project-data-owner-table">
		<thead>
			<tr>
				<?php foreach ($columnSorts as $label => $sortKey) {
					$nextDirection = $sort === $sortKey && $direction === 'ASC' ? 'desc' : 'asc';
					$sortLabel = $label . ($sort === $sortKey ? ' (' . strtolower($direction) . ')' : '');
				?>
					<th scope="col"><a href="<?= $escape($buildUrl(['sort' => $sortKey, 'direction' => $nextDirection, 'page' => 1])) ?>"><?= $escape($sortLabel) ?></a></th>
				<?php } ?>
			</tr>
		</thead>
		<tbody>
			<?php if (!$displayRows) { ?>
				<tr><td colspan="7">No projects match these filters.</td></tr>
			<?php } ?>
			<?php foreach ($displayRows as $index => $row) { ?>
				<tr>
					<td><a href="<?= $escape(APP_PATH_WEBROOT . 'index.php?pid=' . (int) $rows[$index]['project_id']) ?>"><?= $escape($row['Project ID']) ?></a></td>
					<td><?= $escape($row['Project title']) ?></td>
					<td><?= $escape($row["Project Data's Owner"]) ?></td>
					<td><?= $escape($row['Purpose']) ?></td>
					<td><?= $escape($row['Creator']) ?></td>
					<td><?= $escape($row['Creation date']) ?></td>
					<td><?= $escape($row['Project notes']) ?></td>
				</tr>
			<?php } ?>
		</tbody>
	</table>
</div>
<nav class="project-data-owner-pagination" aria-label="Report pages">
	<span>Page <?= $page ?> of <?= $totalPages ?></span>
	<?php if ($page > 1) { ?>
		<a class="btn btn-defaultrc" href="<?= $escape($buildUrl(['page' => $page - 1])) ?>">Previous</a>
	<?php } ?>
	<?php if ($page < $totalPages) { ?>
		<a class="btn btn-defaultrc" href="<?= $escape($buildUrl(['page' => $page + 1])) ?>">Next</a>
	<?php } ?>
</nav>
<?php require_once APP_PATH_DOCROOT . 'ControlCenter/footer.php'; ?>
