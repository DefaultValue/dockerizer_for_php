<?php

declare(strict_types=1);

namespace DefaultValue\Dockerizer\Filesystem;

/**
 * A collection of NewFile objects to be prepared and dumped to the filesystem if needed
 */
class NewFileCollection
{
    public function addFile(NewFile $file)
    {

    }

    public function dump(): void
    {
        try {
            // save original files
            // dump
            // remove original files
        } catch (\Exception $e) {
            // rollback
            // show exception
        }
    }
}
