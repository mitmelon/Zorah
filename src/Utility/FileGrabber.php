<?php
namespace Manomite\Utility;
use \Manomite\{
    Engine\CacheAdapter,
    Exception\ManomiteException as ex
};

include __DIR__ . '/../../autoload.php';

class FileGrabber
{

    public function getImage($file, $type, $isScript = false, $user = null, $default = null){
        $dir = SYSTEM_DIR;
        if(!is_dir($dir)){
            mkdir($dir, 0600, true);
        }
        $adapter = new CacheAdapter();
        $key = strtolower(preg_replace('/[^a-zA-Z0-9-_\.]/s', '', strip_tags(str_replace(array('/', '//', '\'', '\\', chr(0), ' ', '.', '-', '_'), '', $file.$type.$isScript.$user.'images'))));
        $cache = $adapter->getCache($key);
        if($cache !== null){
            //return json_decode($cache, true);
        }
        $image = false;
        if (!empty($file)) {
            $file = basename($file);
            $im = $dir.'/'.$type.'/'.$file;
            if (file_exists($im)) {
                $image = $dir.'/'.$type.'/'.$file;
                $extension = pathinfo($im, PATHINFO_EXTENSION);
                if ($isScript) {
                    $blob = @file_get_contents($im);
                    $blob = base64_encode($blob);
                    $image = 'data:image/'.$extension.';base64,'.$blob;
                }
                $adapter->cache(json_encode($image), $key, 86400);
                return $image;
            }
        }
        if($default !== null){
            $blob = @file_get_contents($default);
            $extension = pathinfo($default, PATHINFO_EXTENSION);
            $blob = base64_encode($blob);
            $image = 'data:image/'.$extension.';base64,'.$blob;
            return $image;
        }
        return null;
    }

    private function getFileFromRemote($user, $file, $type, $isScript = false){
        //future update implementation
    }

    private function getLastPathSegment($url) {
        $path = parse_url($url, PHP_URL_PATH); // to get the path from a whole URL
        $pathTrimmed = trim($path, '/'); // normalise with no leading or trailing slash
        $pathTokens = explode('/', $pathTrimmed); // get segments delimited by a slash
    
        if (substr($path, -1) !== '/') {
            array_pop($pathTokens);
        }
        return end($pathTokens); // get the last segment
    }

    public function getFile($key){
        $s3 = new \Manomite\Engine\S3(strtolower(str_replace(['-', '_', ' '], '', APP_NAME)));
        $listSpace = $s3->listSpace();
        if(empty($listSpace) || $listSpace === false){
            $s3->createBucket();
        };

        $adapter = new CacheAdapter();
        $cache = $adapter->getCache($key);
        if($cache !== null){
            return $cache;
        }
          
        if($s3->checkObject($key) === true){
            $link = $s3->tempLink($key, '+1440 minutes');
            $adapter->cache($link, $key, 86400);
            return $link;
        }
        return '';
    }

    public function deleteFile($key){

        $s3 = new \Manomite\Engine\S3(strtolower(str_replace(['-', '_', ' '], '', APP_NAME)));
        $listSpace = $s3->listSpace();
        if(empty($listSpace) || $listSpace === false){
            $s3->createBucket();
        };

        $adapter = new CacheAdapter();
        if($s3->checkObject($key) === true){
            $s3->deleteFile($key);

            $cache = $adapter->getCache($key);
            if($cache !== null){
                $adapter->delete($key);
            }
            return true;
        } 

        $target = hash_file('sha256', $key);
        if($s3->checkObject($target) === true){
            $s3->deleteFile($target);

            $cache = $adapter->getCache($target);
            if($cache !== null){
                $adapter->delete($target);
            }
            return true;
        }
        return false;
    }

    public function filer($file, $type = '', $default = ''){

        if(!empty($file) && $default === ''){
            $s3 = new \Manomite\Engine\S3(strtolower(str_replace(['-', '_', ' '], '', APP_NAME)));
            $listSpace = $s3->listSpace();
            if(empty($listSpace) || $listSpace === false){
                $s3->createBucket();
            };
            if($type === 'string'){
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                if (preg_match('/^data:([a-zA-Z0-9-]+)\/([a-zA-Z0-9]+);base64,/', $file, $matches)) {
                    // Handle Base64 encoded file contents
                    $fileContent = base64_decode(str_replace($matches[0], '', $file));
                    $mime_type = $finfo->buffer($fileContent);
                    $target = hash_file('sha256', $fileContent);
                } else {
                    $mime_type = $finfo->buffer($file);
                    $target = basename($file);
                }
                if($s3->checkObject($target) === false){
                    $s3->uploadRawFile($fileContent, $mime_type, $target);
                }
            } else {
                $target = basename($file);
                if($s3->checkObject($target) === false){
                    $s3->uploadFile($file, $target);
                }
            }
            if($s3->checkObject($target) === true){
                return $s3->tempLink($target, '+1440 minutes');
            }
        } else {
            if($default !== null){
                $blob = @file_get_contents($default);
                $extension = pathinfo($default, PATHINFO_EXTENSION);
                $blob = base64_encode($blob);
                $image = 'data:image/'.$extension.';base64,'.$blob;
                return $image;
            }
        }
    }
}