<?php namespace TestProject;

class ImageDownloader {
    private $path; // Path to serverside images.
    public $files = array(); // Serverside MD5 hashes. Key = Filename, Value = MD5
    private $legalExtensions = array("jpg","png","bmp"); //Only open these files. Didn't add all the rest of the extensions... 
    private $md5FileName = "image_md5.json"; //serverside md5 file stored in $this->path
    

    /**
     * zipImages
     * Files are the ones to zip and fileName is the zipfile name.
     *
     * @param array  $files      List of files to zip within ImageDownloader->$path
     * @param string $fileName   Zip Filename
     * @return void
     */
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
            if ($zip->addFile($imgFile,$img) === false) throw new Exception("Unable to zip file: " .  $imgFile . " to zip file: " . $fileName);
        }
        $zip->close();
    }

    /**
    * compareImages
    * Compare MD5 file from client to server
    * @param string $clientMd5raw Contents of md5 file (json encoded text)
    * @return array Array with list of [client] missing/to delete/to update/matched images.
    */
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

    /**
    * Construct and set path to server-side images
    * @param string $path Path to server-side images
    */
    function __construct($path) {
        $this->setPath($path); //Forcing setPath at time of construct prevents us from having to trap for empty path throughout.
    }
    
    /**
     * setPath
     * Path to server images. Called by constructor, but available for special cases.
     * @param  string $path
     * @return void
     */
    function setPath($path) {
        if (substr($path,-1) != "/") $path .= "/"; //Add a slash please! Uniform paths ftw.

        if (!file_exists($path)) throw new Exception("Unable to find path: " . $path);
        $this->path = $path;
        out("Path set to: " . $this->path);
    }
    
    /**
     * saveServerHashes
     * Save $this->files to an MD5 hashfile that clients will compare to.
     * @return void
     */
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
    
    /**
     * loadServerHashes
     * Load serverside md5 list.
     * @return void
     */
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
        
    /**
     * rehashServerFiles
     * Load all images in specified folder $this->path and MD5 their contents.
     * @return void
     */
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

    /**
    * _jsonDecodeVersionFile
    *  Requires either filePath or rawJson
    *  @param  string (optional) $filePath   File will be opened and then json_decoded
    *  @param  string (optional) $rawJson    String will be json_decoded 
    *  @return string JSON  Decoded as array
    */
    private function _jsonDecodeVersionFile($filePath=null,$rawJson=null) {
        if ($filePath) $rawJson = $this->_loadVersionFile($filePath);
        $json = json_decode($rawJson,true);
        if ($json === null) throw new Exception("Unable to decode json: \n" . $json . "\n####\n");
        return $json;
    }
    
    /**
     * _loadVersionFile
     * Load a file
     * @param  string $path
     * @return void
     */
    private function _loadVersionFile($path) {
        if (!file_exists($path)) throw new Exception("Unable to find path: " . $path);
        $contents = file_get_contents($path);
        if (!$contents) throw new Exception("Unable to load version file (empty/no access): " . $path);
        return $contents;
    }
}
