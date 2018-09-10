<?php

namespace StrimeEncodingAPI\EncodingBundle\Helpers;

class FilesAction {


    public function __construct() {

    }



    /**
     * @return null
     */
    public function listAllFilesInDirectory($dir, &$results = array()) {
        $files = scandir($dir);

        foreach($files as $key => $value){
            $path = realpath($dir.DIRECTORY_SEPARATOR.$value);
            if(!is_dir($path)) {
                if($value != "." && $value != "..") {
                    $results[] = $path;
                }
            }
            elseif($value != "." && $value != "..") {
                $this->listAllFilesInDirectory($path, $results);
            }
        }

        return $results;
    }



    /**
     * @return null
     */
    public function unlinkFileAndParentDirectory($file) {

        // Set the result variable
        $result = TRUE;

        if(is_file($file)) {

            try {
                // Delete the file
                unlink($file);

                // Set the parent directory
                $parent_dir = dirname($file);

                // Check that the parent is a directory
                if(is_dir($parent_dir)) {

                    // Check if there are files in this directory
                    $files_in_dir = $this->listAllFilesInDirectory($parent_dir);

                    // If there is no file, delete the directory
                    if((count($files_in_dir) == 0) && (strcmp( basename($parent_dir), "thumbnails" ) != 0) && (strcmp( basename($parent_dir), "uploads" ) != 0)) {
                        rmdir($parent_dir);
                    }
                }
            }

            catch (Exception $e) {
                $result = FALSE;
            }

        }

        return $result;
    }

}
