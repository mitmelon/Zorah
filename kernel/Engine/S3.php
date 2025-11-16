<?php
namespace Manomite\Engine;

use \Manomite\Exception\ManomiteException as ex;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\S3\ObjectUploader;
use Aws\S3\MultipartUploader;
use Aws\Exception\MultipartUploadException;
use \Manomite\Engine\Security\Encryption as Secret;

class S3
{
    private $client;
    private $bucket;

    public function __construct($bucket = 'disnam')
    {
        $this->client = new S3Client([
            'version' => 'latest',
            'region'  => 'us-east-1',
            'endpoint' => CONFIG->get('manomite_storage_endpoint'),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key'    => CONFIG->get('manomite_storage_access_key'),
                'secret' => CONFIG->get('manomite_storage_secret_key')
                ]
            ]
        );
        $this->bucket = $bucket;
    }

    public function uploadFile($sourcePath, $targetPath, $security = 'private')
    {
        try {
            return $result = $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key'    => $targetPath,
                'SourceFile' => $sourcePath,
                'ACL'    => $security
                ]);
        } catch (S3Exception $e) {
            new ex('spaceError', 3, $e->getMessage());
            return false;
        }
    }

    public function uploadRawFile($content, $mime_type, $targetPath, $security = 'private')
    {
        try {
            return $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key'    => $targetPath,
                'Body' => $content,
                'ContentType' => $mime_type,
                'ACL'    => $security
                ]);
        } catch (S3Exception $e) {
            new ex('spaceError', 3, $e->getMessage());
            return false;
        }
    }

    public function checkObject($key)
    {
        try {
            return $this->client->doesObjectExist($this->bucket, $key);
        } catch (S3Exception $e) {
            new ex('spaceError', 3, $e->getMessage());
            return false;
        }
    }

    public function getFile($key, $output)
    {
        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key'    => $key
            ]);
            file_put_contents($output, $result['Body']);
        } catch (S3Exception $e) {
            new ex('spaceError', 3, $e->getMessage());
            return false;
        }
    }

    public function tempLink($key, $minutes = '+5 minutes')
    {
        try {
            $cmd = $this->client->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key'    => $key
            ]);
            $request = $this->client->createPresignedRequest($cmd, $minutes);
            $presignedUrl = (string) $request->getUri();
            return $presignedUrl;
        } catch (S3Exception $e) {
            new ex('spaceError', 3, $e->getMessage());
            return false;
        }
    }

    public function tempUploadLink($key, $minutes = '+1 minutes')
    {
        try {
            $cmd = $this->client('PutObject', [
                'Bucket' => $this->bucket,
                'Key'    => $key
            ]);
            
            $request = $this->client->createPresignedRequest($cmd, $minutes);
            $presignedUrl = (string) $request->getUri();
            return $presignedUrl;
        } catch (S3Exception $e) {
            new ex('spaceError', 3, $e->getMessage());
            return false;
        }
    }

    public function uploadDir($dir, $security = 'public-read')
    {
        try {
            return $this->client->uploadDirectory($dir, $this->bucket, '', array(
                'params'      => array('ACL' => $security),
                'concurrency' => 20,
                'debug'       => true
            ));
        } catch (S3Exception $e) {
            new ex('spaceError', 3, $e->getMessage());
            return false;
        }
    }

    public function uploadLargeFile($key, $file, $meta = array())
    {
        $uploader = new MultipartUploader($this->client, $file, [
            'bucket' => $this->bucket,
            'key' => $key,
            'before_upload' => function (\Aws\Command $command) {
                gc_collect_cycles();
            }
            ]);
        do {
            try {
                return $result = $uploader->upload();
            } catch (MultipartUploadException $e) {
                $uploader = new MultipartUploader($this->client, $file, [
                        'state' => $e->getState(),
                    ]);
            }
        } while (!isset($result));
        //Abort a multipart upload if failed
        try {
            return $result = $uploader->upload();
        } catch (MultipartUploadException $e) {
            // State contains the "Bucket", "Key", and "UploadId"
            $params = $e->getState()->getId();
            return $result = $s3Client->abortMultipartUpload($params);
        }
    }

    public function deleteFile($key)
    {
        try {
            return $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
        } catch (S3Exception $e) {
            new ex('spaceError', 3, $e->getMessage());
            return false;
        }
    }

    public function deleteSpace()
    {
        try {
            $this->client->deleteBucket(array('Bucket' => $this->bucket));
            return $this->client->waitUntil('BucketNotExists', array('Bucket' => $this->bucket));
        } catch (S3Exception $e) {
            new ex('spaceError', 3, $e->getMessage());
            return false;
        }
    }

    public function listAllFiles()
    {
        try {
            $iterator = $this->client->getIterator('ListObjects', array(
                'Bucket' => $this->bucket
            ));
            $objects = array();
            foreach ($iterator as $object) {
                $objects[] = $object['Key'];
            }
            return $objects;
        } catch (S3Exception $e) {
            new ex('spaceError', 3, $e->getMessage());
            return false;
        }
    }

    public function listSpace()
    {
        try {
            $result = $this->client->listBuckets();
            $buckets = array();
            foreach ($result['Buckets'] as $bucket) {
                // Each Bucket value will contain a Name and CreationDate
                if($bucket['Name'] === $this->bucket){
                    $buckets[] = array('name' => $bucket['Name'], 'date_created' => $bucket['CreationDate']);
                    break;
                }
            }
            return $buckets;
        } catch (S3Exception $e) {
            new ex('spaceError', 3, $e->getMessage());
            return false;
        }
    }

    public function loadAllSpace()
    {
        try {
            $result = $this->client->listBuckets();
            $buckets = array();
            foreach ($result['Buckets'] as $bucket) {
                // Each Bucket value will contain a Name and CreationDate
                $buckets[] = array('name' => $bucket['Name'], 'date_created' => $bucket['CreationDate']);
            }
            return $buckets;
        } catch (S3Exception $e) {
            new ex('spaceError', 3, $e->getMessage());
            return false;
        }
    }


    public function downloadSpace($pathToStore)
    {
        try {
            $download = \Aws\S3\Sync\AbstractSyncBuilder::getInstance()
            ->setClient($this->client)
            ->setDirectory($pathToStore)
            ->setBucket($this->bucket)
            ->allowResumableDownloads()
            ->build()
            ->transfer();
            return $download->downloadBucket();
        } catch (S3Exception $e) {
            new ex('spaceError', 3, $e->getMessage());
            return false;
        }
    }

    public function createBucket()
    {
        try {
            $result = $this->client->createBucket([
            'Bucket' => $this->bucket,
        ]);
        $response = (new ArrayAdapter)->array_flatten((array)$result);
        $value = json_encode(array(
            'name' => $this->bucket,
            'uri'  => $response['effectiveUri'],
            'date' => $response['date']
        ));
        return $value;//(new Secret(null, $value, true))->encrypt();
        } catch (AwsException $e) {
            new ex('spaceError', 3, $e->getAwsErrorMessage());
            return $e->getAwsErrorCode();
        }
    }
}
