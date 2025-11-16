<?php

namespace Manomite\Engine\Platform;

class Platform 
{
    /**
     * Get the data directory path based on the current platform
     * 
     * @return string Full path to the data directory
     */
    public function getDataDir(): string 
    {
        // Determine the current operating system
        $os = strtolower(PHP_OS);

        // Check if the OS is Windows
        if (strpos($os, 'win') !== false) {
            // Use Windows AppData Local directory
            $dataDir = getenv('LOCALAPPDATA');
        } 
        // Check if the OS is Linux
        elseif (strpos($os, 'linux') !== false) {
            // Use Linux XDG data home directory
            $dataDir = getenv('XDG_DATA_HOME') ?: getenv('HOME') . '/.local/share';
        } 
        // For macOS or other Unix-like systems
        else {
            // Use standard Unix-like data directory
            $dataDir = getenv('HOME') . '/.local/share';
        }

        // Ensure the directory exists
        if (!is_dir($dataDir)) {
            throw new \RuntimeException("Data directory not found: $dataDir");
        }

        return $dataDir;
    }
}
