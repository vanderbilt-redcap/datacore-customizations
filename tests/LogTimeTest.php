<?php namespace Vanderbilt\DataCoreCustomizationsModule;

require_once __DIR__ . '/../../../redcap_connect.php';

class LogTimeTest extends \ExternalModules\ModuleBaseTest
{
    public $module;
    
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
            'programmer_name' => $this->getUniqueRand(),
            'billing_month' => $this->getUniqueRand(),
            'billing_year' => $this->getUniqueRand(),
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

    function testCompareTimeLogs_requireUniqueCheckFields(){
        $assert = function($a, $b){
            $this->assertThrowsException(
                fn() => $this->compareTimeLogs($a, $b),
                'cannot be processed because it is missing',
            );
        };

        $goodLog = $this->createTimeLog();
        $badLog = $this->createTimeLog();
        $badLog['programmer_name'] = '';

        $assert(
            [$goodLog],
            [$badLog],
        );

        $assert(
            [$goodLog],
            [$badLog],
        );
    }

    function testCompareTimeLogs(){
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

    function testArrayDeepDiff_itemOrder(){
        // Make sure item order does not matter (per ksort call in arrayDeepDiff())
        $this->assertEmpty($this->arrayDeepDiff(
            [
                [
                    'programmer_name' => 1,
                    'billing_month' => 1,
                    'billing_year' => 1,
                    'project_role' => 1,
                    'project_name' => 1,
                ]
            ],
            [
                [
                    'billing_month' => 1,
                    'billing_year' => 1,
                    'project_role' => 1,
                    'project_name' => 1,
                    'programmer_name' => 1,
                ]
            ]
        ));
    }

    function testCheckForErrors(){
        $assert = function($log, $error){
            $this->assertSame($error, $this->checkForErrors($log));
        };

        $assert([], $this->getProjectNameError());
        $assert(['project_name' => 1], null);

        $log = [
            'project_name' => 1,
            'project_hours' => 1,
            'project_hours_2' => 1,
        ];
        $this->assertThrowsException(function() use ($log){
            $this->checkForErrors($log);
        }, $this->getHoursError($log));

        $assert([
            'project_name' => 1,
            'project_hours' => 1,
            'project_notes' => 'whatever',
        ], $this->getRequestedByError());
    }
}