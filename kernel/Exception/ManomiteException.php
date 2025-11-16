<?php
namespace Manomite\Exception;
use Manomite\Engine\{
    Fingerprint,
    Log
};

class ManomiteException
{
    /**
    * Creates a new exception.
    *
    * @param string     $save       The file to log exception errors to
    * @param int        $mode       Mode 1 - 6 (1-Debug, 2-Notice, 3-Error, 4-Critical, 5-Emergency, 6-Alert )
    * @param string     $message    Exception message
    */
    private $message;
    public function __construct($save, $mode, $message)
    {
        $device_data = (new Fingerprint)->scan();
        $log = new Log();
        $dir = SYSTEM_DIR.'/errors';
        if(!is_dir($dir)){
            mkdir($dir, 0600, true);
        }
		if($mode === 5){
            $this->reportIssue($save, $message, $device_data);
        }

        if(is_object($message)){
            $message = json_encode([
                'message' => $message->getMessage(),       // Error message
                'code'    => $message->getCode(),          // Error code (if any)
                'file'    => $message->getFile(),          // File where the error occurred
                'line'    => $message->getLine(),          // Line number where the error occurred
                'trace'   => $message->getTraceAsString(),  // Full stack trace as a string
                'time' => date('Y-m-d H:i:s')
            ]);
        }
        $log->showLogger($dir.'/'.$save.'.log', $save, $mode, $message, $device_data);
        //Free log space
        $this->message = $message;
    }
    //No output
    public function return()
    {
        return $this->message;
    }
    //Use catch to grab exceptions
    public function throw()
    {
        throw new \Exception($this->message);
    }
    //Output and exit
    public function exit()
    {
        exit($this->message);
    }
    //Source log
    private function reportIssue($save, $message, $device){

        $log = new Log();
        $payload = array(
            'file' => $save,
            'message' => $message,
            'device' => $device
        );
        $dir = SYSTEM_DIR.'/errors';
        if(!is_dir($dir)){
            mkdir($dir, 0755, true);
        }
        $log->showLogger($dir.'/'.$save.'.log', $save, 3, $message, $payload);
    }

    public function processError($e){
        $logEntry = [
            'message' => $e->getMessage(),       // Error message
            'code'    => $e->getCode(),          // Error code (if any)
            'file'    => $e->getFile(),          // File where the error occurred
            'line'    => $e->getLine(),          // Line number where the error occurred
            'trace'   => $e->getTraceAsString(),  // Full stack trace as a string
            'time' => date('Y-m-d H:i:s')
        ];
        return $logEntry;
    }
}