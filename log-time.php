<?php namespace Vanderbilt\DataCoreCustomizationsModule;

if($_SERVER['REQUEST_METHOD'] === 'GET'){
    ?>
    <script>
        /**
         * Securely retrieve the entries from Assembla via postMessage(),
         * and post them back to this page so they can be processed via PHP.
         */
        const assemblaUrl = 'https://app.assembla.com'
        window.opener.postMessage('loaded', assemblaUrl)
        window.addEventListener("message", (event) => {
            if(event.origin !== assemblaUrl){
                return
            }

            const form = document.createElement('form')
            form.style.display = 'none'
            form.method = 'POST'

            const csrfToken = document.createElement('input')
            csrfToken.name = 'redcap_csrf_token'
            csrfToken.value = <?=json_encode($module->getCSRFToken())?>;
            form.append(csrfToken)

            const data = document.createElement('textarea')
            data.name = 'data'
            data.innerHTML = event.data
            form.append(data)

            document.body.append(form)
            form.submit()
        }, false);
    </script>
    <?php
    return;
}

?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.2.3/css/bootstrap.min.css" integrity="sha512-SbiR/eusphKoMVVXysTKG/7VseWii+Y3FdHrt0EpKgpToZeemhqHeZeLWLhJutz/2ut2Vw1uQEj2MbRF+TVBUA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<style>
    body{
        padding: 10px;
        max-width: 1000px;
        margin: auto;
    }

    #log-new-entries{
        margin: auto;
        margin-top: 30px;
        display: block;
    }
</style>

<h3>Assembla Time Sync</h3>

<?php

$payload = json_decode($_POST['data'], true);
$payloadAge = time() - $payload['time'];
if($payloadAge > 15){
    die('This request has expired.  Please retry from Assembla.');
}

$pid = $module->getSystemSetting('hours-survey-pid');
if(empty($pid)){
    die('Please set the "CORE - Hourly Billing- NEW" system setting.');
}

$programmerName = $module->formatAssemblaUsername($payload['username']);
$programmerId = array_flip($module->getChoiceLabels('programmer_name', $pid))[$programmerName];
$assemblaEntries = $payload['entries'];

$totalAssembalHours = 0;
foreach($assemblaEntries as &$entry){
    $entry['programmer_name'] = (string) $programmerId;
    $entry['billing_year'] = $payload['billing_year'];
    $entry['billing_month'] = $payload['billing_month'];

    foreach([
        'project_hours',
        'project_notes',
        'project_hours_2',
        'project_notes_2',
    ] as $field){
        $value = $entry[$field] ?? '';
        $entry[$field] = $value;

        if(str_contains($field, 'hours')){
            $totalAssembalHours += (int) $value;
        }
    }
}

$existing = \REDCap::getData([
    'project_id' => $pid,
    'return_format' => 'json-array',
    'fields' => array_merge($module->getUniqueCheckFields(), [
        'project_name',
        'project_hours',
        'project_notes',
        'project_hours_2',
        'project_notes_2',
    ]),
    'filterLogic' => "
        [programmer_name] = '$programmerId'
        and [billing_year] = '{$payload['billing_year']}'
        and [billing_month] = '{$payload['billing_month']}'
    "
]);

[$unmatched, $new, $incomplete] = $module->compareTimeLogs($assemblaEntries, $existing);
if(!empty($unmatched)){
    // Only show unmatched logs, since new & incomplete logs can't be correctly determined when unmatched logs exist.
    $module->displayTimeLogs("
        The following time logs already existed in REDCap, but do not have matching entries in Assembla.
        Please resolve these discrepancies on either side, and try syncing again:
    ", $unmatched);
}
else{
    $module->displayTimeLogs("
        The following entries have errors that prevent them from being automatically logged.
        Please resolve the errors and try again, or log these entries manually AFTER using this tool to log as many entries as possible.
    ", $incomplete);

    if(!empty($new)){
        $module->displayTimeLogs("
            The following new entries will be logged.
            Please review them for accuracy before continuing:
        ", $new);

        ?>
        <button id='log-new-entries'>Log New Entries</button>
        <script>
            document.querySelector("#log-new-entries").addEventListener('click', () => {
                const pageLoadTime = <?=json_encode(time())?>;

                const secondsSinceLoad = Date.now()/1000 - pageLoadTime
                if(secondsSinceLoad > 60*60){
                    alert('This page is an hour old. Please close it and reopen it from Assembla to make sure the time logs are up to date.')
                    return
                }

                if(
                    <?=json_encode(!empty($incomplete))?>
                    &&
                    !confirm('Incomplete entries exist.  Are you sure you want to log your time before resolving these?')
                ){
                    return
                }

                const data = new URLSearchParams()
                data.append('redcap_csrf_token', <?=json_encode($module->getCSRFToken())?>)
                data.append('data', JSON.stringify(<?=json_encode($new)?>))

                fetch(<?=json_encode($module->getUrl('save-new-time-logs.php'))?>, {
                    method: 'POST',
                    body: data,
                })
                .then((response) => response.text())
                .then((text) => {
                    if(text === 'success'){
                        alert('New entries have been successfully logged!')
                        close()
                    }
                    else{
                        alert("An error occurred.  See the browser console for details.")
                        console.error(text)
                    }
                });
            })
        </script>
        <?php
    }
    else if(empty($incomplete)){
        echo "Your Assembla time entries totaling $totalAssembalHours hours have already been synced with the REDCap Hours Survey for this month.";
    }
}
