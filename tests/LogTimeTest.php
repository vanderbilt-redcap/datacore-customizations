<?php namespace Vanderbilt\DataCoreCustomizationsModule;

require_once __DIR__ . '/../../../redcap_connect.php';

class LogTimeTest extends \ExternalModules\ModuleBaseTest
{
    public DataCoreCustomizationsModule $module;
    
    private $rands = [];

    private function getUniqueRand(){
        $value = rand();
        if(!isset($this->rands[$value])){
            $this->rands[$value] = true;
            return $value;
        }
        else{
            // This value has already been used.  Get another one.
            return $this->getUniqueRand();
        }
    }

    private function createTimeLog(){
        $log = [
            'project_role' => $this->getUniqueRand(),
            'project_name' => $this->getUniqueRand(),
            'project_hours' => '',
            'project_notes' => '',
            'project_hours_2' => '',
            'project_notes_2' => '',
        ];

        $hourFieldName = 'project_hours';
        $notesFieldName = 'project_notes';
        if(rand(1,2) === 2){
            $hourFieldName .= '_2';
            $notesFieldName .= '_2';
        }

        $log[$hourFieldName] = $this->getUniqueRand();
        $log[$notesFieldName] = $this->getUniqueRand() . ' blah blah. Requested by Janice Joplin.';

        return $log;
    }

    function removeRequestedBy($log){
        foreach(['project_notes', 'project_notes_2'] as $fieldName){
            $log[$fieldName] = str_replace('Requested by', '', $log[$fieldName]);
        }
            
        return $log;
    }

    function testLogTime(){
        $existingLog = $this->createTimeLog();
        $newLog = $this->createTimeLog();
        $unmatchedLog = $this->createTimeLog();

        $incompleteLog = $this->removeRequestedBy($this->createTimeLog());
        $incompleteLogWithError = $incompleteLog;
        $incompleteLogWithError['error'] = $this->getRequestedByError();

        $this->assertCompareTimeLogs(
            [
                $existingLog,
                $incompleteLog,
                $newLog,
            ],
            [
                $existingLog,
            ],
            function($unmatched, $new, $incomplete) use ($newLog, $incompleteLogWithError){

                $this->assertEmpty($unmatched);
                $this->assertSame([$newLog], $new);
                $this->assertSame([$incompleteLogWithError], $incomplete);
            }
        );
        
        $this->assertCompareTimeLogs(
            [
                $existingLog,
                $newLog,
                $incompleteLog,
            ],
            [
                $existingLog,
                $unmatchedLog,
            ],
            function($unmatched, $new, $incomplete) use ($unmatchedLog){
                // Ensure that only unmatched rows are returned, and not any others.
                $this->assertSame(1, count($unmatched));
                $this->assertSame($unmatchedLog, $unmatched[0]);
                $this->assertSame([$unmatchedLog], $unmatched);
                $this->assertEmpty($new);
                $this->assertEmpty($incomplete);
            }
        );
    }

    function assertCompareTimeLogs($assemblaLogs, $existingLogs, $assert){
        // Test all permutations to make sure order does not matter
        foreach($this->permutations($assemblaLogs) as $assemblaLogsPermutation){
            foreach($this->permutations($existingLogs) as $existingLogsPermutation){
                $assert(...$this->module->compareTimeLogs($assemblaLogsPermutation, $existingLogsPermutation));
            }
        }
    }

    // Copied from https://stackoverflow.com/a/27160465
    function permutations(array $elements){
        if (count($elements) <= 1) {
            yield $elements;
        } else {
            foreach ($this->permutations(array_slice($elements, 1)) as $permutation) {
                foreach (range(0, count($elements) - 1) as $i) {
                    yield array_merge(
                        array_slice($permutation, 0, $i),
                        [$elements[0]],
                        array_slice($permutation, $i)
                    );
                }
            }
        }
    }
}