<?php

// Used by our Assembla customizations

$pid = $module->getSystemSetting('hours-survey-pid');
$project = new \Project($pid);
$sql = $project->metadata['project_name']['element_enum'];
$result = $module->query($sql, []);

echo "<p>Select an option from the hours survey project dropdown:</p>";
echo "<select style='display: none'>";
echo "<option value=''></option>";
echo "<option value='None'></option>";
foreach($result->fetch_all() as $project){
    $project = $module->escape($project);
    echo "<option value='{$project[0]}'>{$project[1]}</option>";
}
echo "</select>";

?>
<style>
    .choices__inner{
        min-height: 20px !important;
        width: 95% !important;
    }
</style>

<link
  rel="stylesheet"
  href="https://cdn.jsdelivr.net/npm/choices.js@9.0.1/public/assets/styles/choices.min.css"
/>
<script src="https://cdn.jsdelivr.net/npm/choices.js@9.0.1/public/assets/scripts/choices.min.js"></script>

<script>
    (() => {
        const select = document.querySelector('select')

        const choices = new Choices(select)
        choices.showDropdown()
            
        select.addEventListener('change', () => {
            const option = select.selectedOptions[0]

            let value = option.textContent
            if(value !== 'None'){
                value += ' (' + option.value + ')'
            }

            window.parent.postMessage(value, '*')
        })
    })()
</script>