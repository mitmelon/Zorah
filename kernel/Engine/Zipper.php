<?php
namespace Manomite\Engine;

use ZipArchive;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Zipper
{
    private $zip;
    private $outputPath;
    private $logger;
    private $isOpen = false;

    /**
     * Constructor initializes the ZIP archive.
     *
     * @param string $output Path where the ZIP file will be saved.
     * @param LoggerInterface|null $logger Optional PSR-3 logger for logging operations.
     * @throws \Exception If ZIP file cannot be created.
     */
    public function __construct(string $output, LoggerInterface $logger = null)
    {
        $this->outputPath = $output;
        $this->logger = $logger ?? new NullLogger();
        $this->zip = new ZipArchive();
        if ($this->zip->open($this->outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->logger->error('Failed to create ZIP file', ['path' => $this->outputPath]);
            throw new \Exception('Failed to create ZIP file at: ' . $this->outputPath);
        }
        $this->isOpen = true;
        $this->logger->info('ZIP archive created', ['path' => $this->outputPath]);
    }

    /**
     * Adds a file to the ZIP from a filesystem path.
     *
     * @param string $filename Name of the file inside the ZIP.
     * @param string $filePath Path to the file on the filesystem.
     * @return self
     * @throws \Exception If the file does not exist or cannot be added.
     */
    public function addFileFromPath(string $filename, string $filePath): self
    {
        $this->ensureZipOpen();
        if (!file_exists($filePath)) {
            $this->logger->error('File does not exist', ['path' => $filePath]);
            throw new \Exception('File does not exist: ' . $filePath);
        }
        if ($this->zip->addFile($filePath, $filename) === false) {
            $this->logger->error('Failed to add file to ZIP', ['filename' => $filename, 'path' => $filePath]);
            throw new \Exception('Failed to add file to ZIP: ' . $filename);
        }
        $this->logger->info('Added file to ZIP', ['filename' => $filename, 'path' => $filePath]);
        return $this;
    }

    /**
     * Adds a file to the ZIP from raw data.
     *
     * @param string $filename Name of the file inside the ZIP.
     * @param string $data Raw data to add.
     * @param string|null $comment Optional comment for the file.
     * @return self
     * @throws \Exception If the file cannot be added.
     */
    public function addFileFromRaw(string $filename, string $data, string $comment = null): self
    {
        $this->ensureZipOpen();
        if ($this->zip->addFromString($filename, $data) === false) {
            $this->logger->error('Failed to add raw data to ZIP', ['filename' => $filename]);
            throw new \Exception('Failed to add raw data to ZIP: ' . $filename);
        }
        if ($comment !== null) {
            $this->zip->setCommentName($filename, $comment);
        }
        $this->logger->info('Added raw data to ZIP', ['filename' => $filename]);
        return $this;
    }

    /**
     * Deletes a file from the ZIP archive.
     *
     * @param string $filename Name of the file inside the ZIP to delete.
     * @return self
     * @throws \Exception If the file cannot be deleted.
     */
    public function deleteFile(string $filename): self
    {
        $this->ensureZipOpen();
        if ($this->zip->deleteName($filename) === false) {
            $this->logger->error('Failed to delete file from ZIP', ['filename' => $filename]);
            throw new \Exception('Failed to delete file from ZIP: ' . $filename);
        }
        $this->logger->info('Deleted file from ZIP', ['filename' => $filename]);
        return $this;
    }

    /**
     * Retrieves the contents of a file from the ZIP without extracting.
     *
     * @param string $filename Name of the file inside the ZIP.
     * @return string File contents.
     * @throws \Exception If the file cannot be read.
     */
    public function getFileData(string $filename): string
    {
        $this->ensureZipOpen();
        $data = $this->zip->getFromName($filename);
        if ($data === false) {
            $this->logger->error('Failed to read file from ZIP', ['filename' => $filename]);
            throw new \Exception('Failed to read file from ZIP: ' . $filename);
        }
        $this->logger->info('Read file data from ZIP', ['filename' => $filename]);
        return $data;
    }

    /**
     * Gets statistics about the ZIP archive.
     *
     * @return array Statistics including file count, total size, and file details.
     * @throws \Exception If the ZIP cannot be accessed.
     */
    public function getStatistics(): array
    {
        $this->ensureZipOpen();
        $stats = [
            'file_count' => $this->zip->numFiles,
            'total_size' => 0,
            'files' => []
        ];

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $fileStats = $this->zip->statIndex($i);
            if ($fileStats === false) {
                $this->logger->error('Failed to get stats for file at index', ['index' => $i]);
                continue;
            }
            $stats['total_size'] += $fileStats['comp_size'];
            $stats['files'][$fileStats['name']] = [
                'size' => $fileStats['size'],
                'compressed_size' => $fileStats['comp_size'],
                'mtime' => date('Y-m-d H:i:s', $fileStats['mtime']),
                'comment' => $this->zip->getCommentName($fileStats['name']) ?: ''
            ];
        }

        $this->logger->info('Retrieved ZIP statistics', ['file_count' => $stats['file_count'], 'total_size' => $stats['total_size']]);
        return $stats;
    }

    /**
     * Previews the contents of the ZIP without extracting.
     *
     * @return array List of files and their metadata.
     * @throws \Exception If the ZIP cannot be accessed.
     */
    public function previewContents(): array
    {
        $this->ensureZipOpen();
        $contents = [];

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $fileStats = $this->zip->statIndex($i);
            if ($fileStats === false) {
                $this->logger->error('Failed to get file info at index', ['index' => $i]);
                continue;
            }
            $contents[] = [
                'name' => $fileStats['name'],
                'size' => $fileStats['size'],
                'compressed_size' => $fileStats['comp_size'],
                'mtime' => date('Y-m-d H:i:s', $fileStats['mtime']),
                'comment' => $this->zip->getCommentName($fileStats['name']) ?: ''
            ];
        }

        $this->logger->info('Previewed ZIP contents', ['file_count' => count($contents)]);
        return $contents;
    }

    /**
     * Finalizes and saves the ZIP file.
     *
     * @throws \Exception If the ZIP cannot be finalized.
     */
    public function complete(): void
    {
        $this->ensureZipOpen();
        if ($this->zip->close() === false) {
            $this->logger->error('Failed to finalize ZIP', ['status' => $this->zip->getStatusString()]);
            throw new \Exception('Failed to finalize ZIP file: ' . $this->zip->getStatusString());
        }
        $this->isOpen = false;
        if (!file_exists($this->outputPath)) {
            $this->logger->error('ZIP file was not created', ['path' => $this->outputPath]);
            throw new \Exception('ZIP file was not created at: ' . $this->outputPath);
        }
        $this->logger->info('ZIP file finalized', ['path' => $this->outputPath]);
    }

    /**
     * Gets the path to the ZIP file.
     *
     * @return string
     */
    public function getOutputPath(): string
    {
        return $this->outputPath;
    }

    /**
     * Recursively deletes a directory and its contents.
     *
     * @param string $folder Path to the directory to delete.
     * @throws \Exception If deletion fails.
     */
    public function deleteFilesThenSelf(string $folder): void
    {
        if (!is_dir($folder)) {
            $this->logger->error('Directory does not exist for deletion', ['path' => $folder]);
            throw new \Exception('Directory does not exist: ' . $folder);
        }

        foreach (new \DirectoryIterator($folder) as $f) {
            if ($f->isDot()) {
                continue;
            }
            if ($f->isFile()) {
                if (unlink($f->getPathname()) === false) {
                    $this->logger->error('Failed to delete file', ['path' => $f->getPathname()]);
                    throw new \Exception('Failed to delete file: ' . $f->getPathname());
                }
            } elseif ($f->isDir()) {
                $this->deleteFilesThenSelf($f->getPathname());
            }
        }

        if (rmdir($folder) === false) {
            $this->logger->error('Failed to delete directory', ['path' => $folder]);
            throw new \Exception('Failed to delete directory: ' . $folder);
        }
        $this->logger->info('Deleted directory and contents', ['path' => $folder]);
    }

    /**
     * Ensures the ZIP archive is open.
     *
     * @throws \Exception If the ZIP is not open.
     */
    private function ensureZipOpen(): void
    {
        if (!$this->isOpen) {
            $this->logger->error('ZIP archive is not open', ['path' => $this->outputPath]);
            throw new \Exception('ZIP archive is not open. Call complete() to finalize or create a new Zipper instance.');
        }
    }

    /**
     * Destructor to ensure the ZIP is closed.
     */
    public function __destruct()
    {
        if ($this->isOpen) {
            try {
                $this->zip->close();
                $this->isOpen = false;
                $this->logger->info('ZIP archive closed in destructor', ['path' => $this->outputPath]);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to close ZIP in destructor', ['path' => $this->outputPath, 'error' => $e->getMessage()]);
            }
        }
    }
}