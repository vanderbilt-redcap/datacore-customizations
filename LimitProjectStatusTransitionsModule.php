<?php namespace Vanderbilt\LimitProjectStatusTransitionsModule;

class LimitProjectStatusTransitionsModule extends \ExternalModules\AbstractExternalModule
{
    function redcap_every_page_before_render(){
        if(!$this->isSuperUser() && PAGE === 'ProjectGeneral/change_project_status.php'){
            /**
             * Block the status transition.
             * This is a failsafe in case the UI modifications ever break after a future REDCap UI update.
             */
            echo 0; // Code 0 triggers an error message on the client side
            $this->exitAfterHook();
        }
    }
}