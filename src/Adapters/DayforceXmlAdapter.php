<?php

namespace Conduit\Adapters;

use Exception;
use Conduit\Drivers\StorageDriver;
use League\Flysystem\Sftp\SftpAdapter;
use Conduit\Exceptions\BridgeTransactionException;

/**
 * Class DayforceXmlAdapter
 * @package Conduit\Adapters
 */
class DayforceXmlAdapter extends BaseAdapter
{
    /**
     * DayforceXmlAdapter constructor.
     * @param array $config
     * @param ResponseTransformer|null $transformer
     */
    public function __construct(array $config = [], ResponseTransformer $transformer = null)
    {
        parent::__construct($config, $transformer);
        
        $this->setDriver(app(StorageDriver::class, ['serviceConfig' => (array)$config]));
    }
    
    /**
     * @param array $data
     * @param string $fileSuffix
     * @return array
     * @throws BridgeTransactionException
     */
    public function postEmployees(array $data, $fileSuffix = '') : array
    {
        $employeeId = collect($data)->pluck('Employees')->collapse()->pluck('XRefCode')->unique();
        $employeeId = ($employeeId->count() == 1) ? $employeeId->first() : 'Data';
        #$timestamp = date('Y-m-d_H:i:s');
        $filename = rtrim('New_Hire_'.$employeeId, '_');
        $location = rtrim("Import/HRImport/$filename"."_$fileSuffix", '_');
        $action = 'put';
        $result = $this->driver->send($location, $action, $data);
        $this->flash();
        $this->finish($result['file_path'], $result['root']);
        
        return $result;
    }
    
    /**
     * @param array $data
     * @return array
     * @throws BridgeTransactionException
     */
    public function patchEmployees(array $data) : array
    {
        $employeeId = collect($data)->pluck('Employees')->collapse()->pluck('XRefCode')->unique()->filter();
        
        switch ($employeeId->count()) {
            case 0:
                $employeeId = array_get($data, 'xRefCode');
                break;
            case 1:
                $employeeId = $employeeId->first();
                break;
            default:
                $employeeId = 'Data';
        }
        
        #$timestamp = date('Y-m-d_H:i:s');
        $filename = rtrim('Update_'.$employeeId, '_');
        $location = "Import/HRImport/$filename";
        $action = 'put';
        $result = $this->driver->send($location, $action, $data);
        $this->flash();
        $this->finish($result['file_path'], $result['root']);
        
        return $result;
    }
    
    /**
     * @param array $data
     * @return array
     */
    public function sendTimesheetData(array $data) : array
    {
        // TODO: Refactor the heavy-lifting of App\Console\Commands\CeridianTimeSheet into this method
    }
    
    /**
     * Storage::put() at it's heart, uses the core fputs() function.  Specifically in \phpseclib\Net\SSH2::_send_binary_packet(), in the case of the SFTP adapter.  And according to an issue raised by Ceridian's testing, as well as https://stackoverflow.com/questions/3304727/does-php-wait-for-filesystem-operations-like-file-put-contents-to-complete-bef, fputs() may not be entirely synchronous.  So the best way to ensure that the data-packet has been fully flashed to its destination is to close the file-handle that it's using first.  The Storage facade is smart enough to reestablish the connection for subsequent files.  This is a performance hit, but a necessary evil to ensure that files don't get marked '.ready' prematurely.
     */
    private function flash()
    {
        //The chain of methods needed to reach the file handle will not always be present for all adapters.  But it's just the SFTP one we're concerned with for this case.
        if (get_class($this->driver->getClient()->getDriver()->getAdapter()) == SftpAdapter::class) {
            $this->driver->getClient()->getDriver()->getAdapter()->getConnection()->_disconnect(11);
            //11 = \phpseclib\Net\SSH2::NET_SSH2_DISCONNECT_BY_APPLICATION, but those constants are established at runtime, and are not actually publicly available.
        }
    }
    
    /**
     * @param string $filePath
     * @param string $root
     * @return bool
     * @throws BridgeTransactionException
     */
    public function finish($filePath, $root)
    {
        //This gets called only when both files have been successfully uploaded, to indicate that they're ready for processing
        try {
            $success = $this->driver->getClient()->move("$filePath.xml", "$filePath.ready");
        } catch (Exception $error) {
            throw new BridgeTransactionException("Failed to transmit '$root/$filePath'.", 0, $error);
        }
        
        return $success;
    }
    
    /**
     * @param array $data
     * @param string $filename
     * @param string $path
     * @param bool $finish
     * @return array
     * @throws BridgeTransactionException
     */
    public function postExpenses(array $data, $filename = 'Expenses', $path = 'Import/EmployeePayAdjustmentImport', $finish = false) : array
    {
        $action = 'put';
        $location = trim($path, '/').'/'.trim($filename);
        $result = $this->driver->send($location, $action, $data);
        $this->flash();
        if ($finish) {
            $this->finish($result['file_path'], $result['root']);
        }
        
        return $result;
    }
}
