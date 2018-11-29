<?php

namespace Conduit\Adapters;

use Conduit\Exceptions\BridgeTransactionException;

/**
 * Class DayforceOdataAdapter
 * @package Conduit\Adapters
 */
class DayforceOdataAdapter extends BaseAdapter
{
    /**
     * @param $date
     * @param string $status
     * @return mixed
     * @throws BridgeTransactionException
     */
    public function pullWorkflowsByStatus($date, $status = 'COMPLETED')
    {
        $date = date('n/j/Y', strtotime($date));
        
        $route = 'SaSRWorkflowStatusODATA';
        $method = 'GET';
        $parameters = ['$filter' => "Initiated_Date ge '$date' and Employee_Reference_Code ne 'NULL' and Workflow_Status eq '".strtoupper($status)."'"];
        
        $map = [
            'Employee Handbook' => 'dayforceEmployeeHandbook',
            'Arbitration Agreement' => 'dayforceArbitrationAgreement',
            'Confidential Information' => 'dayforceConfidentialInformation'
        ];
        
        $dayforceData =  array_get($this->driver->send($route, $method, $parameters), 'content');
        $sortedData = collect($dayforceData->value)->filter(function($item) {
            return in_array($item->Form, ['Employee Handbook', 'Arbitration Agreement', 'Confidential Information']);
        })->mapToGroups(function($item) use ($map) {
            $key = array_get($map, $item->Form, $item->Form);
            return [$key => $item->Employee_Reference_Code];
        })->toArray();
        
        return $sortedData;
    }
}
