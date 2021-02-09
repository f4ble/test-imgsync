<?php
/*
Requirements:
apt-get install php-zip

*/
class ImageDownloader {
    private $path; // Path to serverside images.
    public $files = array(); // Key = Filename, Value = MD5
    private $legalExtensions = array("jpg","png","bmp"); //Only open these files
    private $md5FileName = "image_md5.json";


    //Files are the ones to zip and fileName is the zipfile name.
    function zipImages($files,$fileName) {
        $zip = new ZipArchive();

        if (file_exists($fileName)) {
            throw new Exception("Unable to open zip file. File exists");
        }

        out("Creating zip archive: " . $fileName);
        if ($zip->open($fileName, ZipArchive::CREATE) !== true) {
            throw new Exception("Zip: Unable to open file $fileName");
        }
        foreach($files as $img=>$md5) {
            $imgFile = $this->path . $img;
            out("Zipping file: " . $imgFile);
            if ($zip->addFile($imgFile,ZipArchive::FL_NODIR) === false) throw new Exception("Unable to zip file: " .  $imgFile . " to zip file: " . $fileName);
        }
        $zip->close();
    }

    function compareImages($clientMd5raw) {
        $clientJson = $this->_jsonDecodeVersionFile(null,$clientMd5raw);
        
        $result = array( // "missing"=>filename=>md5 (using filename also as key for easy comparison/merging)
            "missing" => array(), // client missing file, 
            "delete" => array(), // delete is there in case this ever has access to clientside files and can actually delete files. Or we can textually inform clientside.
            "update" => array(), // md5 has changed
            "matched" => array() // Files that are up to date
        );

        foreach($this->files as $filename=>$md5) {
            //Matched
            if (isset($clientJson[$filename]) && $clientJson[$filename] == $md5) {
                $result["matched"][$filename] = $md5;
            }
            //Missing: Server file does not exist in client package
            elseif (!isset($clientJson[$filename])) {
                $result["missing"][$filename] = $md5;
            }
            //Update: MD5 changed
            elseif (isset($clientJson[$filename]) && $clientJson[$filename] != $this->files[$filename]) {
                $result["update"][$filename] = $md5;
            }

            //Unset update/missing and the remaining will be clientside files to delete
            unset($clientJson[$filename]);
        }
        //Clientside files to delete
        $result["delete"] = $clientJson; 

        return $result;
    }

    function __construct($path) {
        $this->setPath($path); //Forcing setPath at time of construct prevents us from having to trap for empty path throughout.
    }

    function setPath($path) {
        if (substr($path,-1) != "/") $path .= "/"; //Add a slash please! Uniform paths ftw.

        if (!file_exists($path)) throw new Exception("Unable to find path: " . $path);
        $this->path = $path;
        out("Path set to: " . $this->path);
    }

    function saveServerHashes() {
        if (!count($this->files)) {
            out("Skipping saving server hashes. Empty file list");
            return;
        }

        $md5File = $this->path . $this->md5FileName;

        $json = json_encode($this->files);
        if ($json === false) throw new Exception("Unable to json encode server file hashes.");

        $result = file_put_contents($md5File,$json);

        //Explicit False. Can return 0 (bytes written)
        if ($result === false) throw new Exception("Unable to save MD5 file - check permissions: " . $md5File);

        out("Server hashes saved to: " . $md5File);
    }

    function loadServerHashes() {
        $md5File = $this->path . $this->md5FileName;

        //Server has never md5 hashed it's image files.
        if (!file_exists($md5File)) {
            out("Creating server md5 file: " . $md5File);
            $this->rehashServerFiles();
            $this->saveServerHashes();
            return;
        }

        $rawJson = file_get_contents($md5File);
        if ($rawJson == false) throw new Exception("Unable to load MD5  file: " . $md5File);

        $files = json_decode($rawJson,true);
        if ($files === false) throw new Exception("Unable to decode json in file " . $md5File);

        $this->files = $files;
        out("Loaded server hashes: " . count($this->files) . "\n\t" . join("\n\t",array_keys($this->files)) . "\n");
        
    }

    //Load all images in specified folder $this->path and MD5 their contents.
    function rehashServerFiles() {
        $array = scandir($this->path);

        $files = array();
        foreach($array as $file) {
            $ext = pathinfo($file,PATHINFO_EXTENSION);

            //Only allow certain file types based on extensions.
            if (!in_array(strtolower($ext),$this->legalExtensions)) continue;

            $fullPath = $this->path . $file;
            $contents = file_get_contents($fullPath);
            if (!$contents) throw new Exception("Unable to load image file: " . $fullPath);

            $files[$file] = md5($contents);
        }
        out("rehashServerFiles found " . count($files) . " images files.");
        $this->files = $files;
    }


    private function _jsonDecodeVersionFile($filePath=null,$rawJson=null) {
        if ($filePath) $rawJson = $this->_loadVersionFile($filePath);
        $json = json_decode($rawJson,true);
        if ($json === null) throw new Exception("Unable to decode json: \n" . $json . "\n####\n");
        return $json;
    }

    private function _loadVersionFile($path) {
        if (!file_exists($path)) throw new Exception("Unable to find path: " . $path);
        $contents = file_get_contents($path);
        if (!$contents) throw new Exception("Unable to load version file (empty/no access): " . $path);
        return $contents;
    }
}
