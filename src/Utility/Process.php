<?php
namespace Manomite\Utility;

use Manomite\{
    Engine\Security\Encryption as Secret,
    Engine\Queue\Redis\Redis,
    Engine\Fingerprint,
    Engine\Security\PostFilter,
    Engine\File,
};

class Process
{
    private $pidDir;
    private $logDir;

    public function __construct()
    {
        $this->logDir = SYSTEM_DIR . '/log/process';
        $this->pidDir = $this->logDir . '/pids';
        
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
        if (!is_dir($this->pidDir)) {
            mkdir($this->pidDir, 0755, true);
        }
    }

    public function create($script, $descriptorspec = [], array $args = [])
    {
        $fileName = basename($script);
        $pidFile = $this->pidDir . '/' . $fileName . '.pid';

        if ($this->isProcessRunning($fileName, $script)) {
            $pid = (int) file_get_contents($pidFile);
            return ['running' => true, 'pid' => $pid];
        }

        if (empty($descriptorspec)) {
            $descriptorspec = [
                0 => ['pipe', 'r'],
                1 => ['file', $this->logDir . '/' . $fileName . '.log', 'a'],
                2 => ['file', $this->logDir . '/' . $fileName . '_error.log', 'a'],
            ];
        }

        $php = defined('PHP_EXEC_BIN') ? PHP_EXEC_BIN : 'php';
        $options = implode(' ', $args);
        $isWindows = stripos(PHP_OS, 'win') === 0;

        $command = $isWindows
            ? "start /B \"\" \"$php\" \"$script\" $options"
            : "$php \"$script\" $options > {$this->logDir}/{$fileName}.log 2>&1 &";

        $pipes = [];
        $proc = proc_open($command, $descriptorspec, $pipes, dirname($script));

        if (!is_resource($proc)) {
            return ['running' => false, 'pid' => null, 'error' => 'Failed to open process'];
        }

        $status = proc_get_status($proc);
        $pid = $status['pid'];

        if ($status['running']) {
            if (!$isWindows && stripos(PHP_OS, 'linux') === 0) {
                $pid++;
            }

            if (isset($pipes[0])) {
                fwrite($pipes[0], "");
                fclose($pipes[0]);
            }
            if (isset($pipes[1])) fclose($pipes[1]);
            if (isset($pipes[2])) fclose($pipes[2]);

            file_put_contents($pidFile, $pid);

            sleep(5); // Wait for process to stabilize
            if ($this->isProcessRunning($fileName, $script)) {
                $actualPid = (int) file_get_contents($pidFile);
                return ['running' => true, 'pid' => $actualPid];
            } else {
                if (file_exists($pidFile)) unlink($pidFile);
                return ['running' => false, 'pid' => null, 'error' => 'Process didn’t persist'];
            }
        }

        if (is_resource($proc)) proc_close($proc);
        return ['running' => false, 'pid' => null, 'error' => 'Process failed to start'];
    }

    public function kill($pid)
    {
        $isWindows = stripos(PHP_OS, 'win') === 0;
        if ($isWindows) {
            exec("taskkill /PID $pid /F 2>&1", $output, $return);
        } else {
            exec("kill -9 $pid 2>&1", $output, $return);
        }
        return $return === 0;
    }

    public function isProcessRunning($fileName, $script = null)
    {
        $pidFile = $this->pidDir . '/' . $fileName . '.pid';
        $isWindows = stripos(PHP_OS, 'win') === 0;

        $storedPid = file_exists($pidFile) ? (int) file_get_contents($pidFile) : 0;
        $scriptPath = $script ? $script : $fileName;

        if ($isWindows) {
            exec("wmic process where \"name='php.exe' and commandline like '%$scriptPath%'\" get ProcessId 2>NUL", $output);
            foreach ($output as $line) {
                $pid = (int) trim($line);
                if ($pid > 0) {
                    if ($storedPid !== $pid && $storedPid > 0) {
                        exec("tasklist /FI \"PID eq $storedPid\" 2>NUL", $checkOld);
                        if (!empty($checkOld) && strpos(implode("\n", $checkOld), "$storedPid") !== false) {
                            $this->kill($storedPid);
                        }
                    }
                    file_put_contents($pidFile, $pid);
                    return true;
                }
            }
        } else {
            exec("ps aux | grep '[p]hp.*$scriptPath'", $output);
            if (!empty($output)) {
                foreach ($output as $line) {
                    $parts = preg_split('/\s+/', trim($line));
                    if (isset($parts[1]) && is_numeric($parts[1])) {
                        $pid = (int) $parts[1];
                        if ($storedPid !== $pid && $storedPid > 0) {
                            if (posix_kill($storedPid, 0)) {
                                $this->kill($storedPid);
                            }
                        }
                        file_put_contents($pidFile, $pid);
                        return true;
                    }
                }
            }
        }

        if (file_exists($pidFile)) unlink($pidFile);
        return false;
    }

    public function send_to_queue(array $payload, string $route, string $workerName)
    {
        $payload = json_encode($payload);
        $id = hash('sha256', $payload);
        $pipe = base64_encode(gzcompress((new Secret($payload, 'transit_key'))->encrypt()));
        (new Redis(json_encode(['service' => ['route' => '/' . $route, 'pipe' => $pipe], 'workerID' => $id]), $workerName))->send();
        
        // Auto-start worker if not running
        $workerFile = $workerName . '.php';
        $workerPath = dirname(__DIR__) . '/Workers/' . $workerFile;
        
        if (file_exists($workerPath)) {
            if (!$this->isProcessRunning($workerFile, $workerPath)) {
                $this->create($workerPath);
            }
        }
    }

    public function stopAllServers($serverDir)
    {
        $files = glob($serverDir . '/*.php');
        foreach ($files as $file) {
            $fileName = basename($file);
            $pidFile = $this->pidDir . '/' . $fileName . '.pid';
            if (file_exists($pidFile)) {
                $pid = (int) file_get_contents($pidFile);
                if ($this->kill($pid)) {
                    unlink($pidFile);
                }
            }
        }
    }
}