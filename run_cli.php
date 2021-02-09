<?php namespace TestProject;

require_once("ImageDownloader.php");

//Because we no like them globals!
abstract class Settings {
    public static $verbose = true; //Toggle with -q
}
$options = getopt("zqrf:"); //Commandline options
if (isset($options["q"])) Settings::$verbose = false;


//Easy manipulation of command line output, rather than using echo()
//Ideally I prefer to create specific "toolboxes" (classes) for these cases that I use across projects.
//This can for example be expanded to use output buffering and dump that to a log file rather than terminal output
function out($msg) {
    if (!Settings::$verbose) return false;
    echo date("H:i:s") . " # $msg\n";
}

$path = getcwd() . "/images"; //Path to serverside images

$dl = new ImageDownloader($path);
$dl->loadServerHashes();
out("Init complete.\n###");



//Logic
if (isset($options["r"])) {
    //-r Rehash server files
    out("Rehashing server files");
    $dl->rehashServerFiles();
    $dl->saveServerHashes();
}
elseif (isset($options["f"])) {
    //-f [file]  Compare md5 with server
    out("Comparing jsons");
    $raw = file_get_contents($options["f"]);
    if ($raw === false) throw new Exception("Unable to open file: " . $options["f"]);
    
    out("Synchronizing images");
    $result = $dl->compareImages($raw);
    if (Settings::$verbose) var_dump($result); 

    //ZIP DIFF!
    if (isset($options["z"])) {
        $zipFile = date("Y-m-d") . "-images.zip";

        $imagesFiles = array_merge($result["missing"],$result["update"]);
        if (Settings::$verbose) var_dump($imagesFiles);

        $dl->zipImages($imagesFiles,$zipFile);
    }
}
elseif (isset($options["z"])) {
    out("Zipping all server files.");
    //ZIP ALL!
    $zipFile = date("Y-m-d His") . "-images.zip";

    $imagesFiles = $dl->files;
    if (Settings::$verbose) var_dump($imagesFiles);

    $dl->zipImages($imagesFiles,$zipFile);
}
else {
    out("Nothing to do...");
}
