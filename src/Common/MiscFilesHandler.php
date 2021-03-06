<?php

namespace Kaliop\eZP5UI\Common;

use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Console\Output\OutputInterface;

class MiscFilesHandler extends Handler
{
    protected $sourceDir;
    protected $targetDir;

    /**
     * @param string $sourceDir
     * @param string $targetDir the folder where files are stored.
     *                        Structure: <targetDir>
     *                                   |- common
     *                                   | |- <dir>
     *                                   |   |- <file>
     *                                   |- <env>
     *                                     |- <dir>
     *                                     | |- <file>
     * @param OutputInterface $outputInterface
     * @throws \Exception
     */
    public function __construct($sourceDir, $targetDir, OutputInterface $outputInterface = null)
    {
        if (!is_dir($sourceDir)) {
            throw new \Exception("Source dir '$sourceDir' is not a directory");
        }

        $this->sourceDir = $sourceDir;
        $this->targetDir = $targetDir;

        $this->setOutputInterface($outputInterface);
    }

    public function install($env, $doOverwrite = false)
    {
        $fs = new Filesystem();

        // 'common' file will be overtaken by per-env ones if they do exist
        foreach(array('common', $env) as $env) {
            $sourceDir = $this->sourceDir . '/' . $env . '/';

            if (!is_dir($sourceDir)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDir));

            $it->rewind();
            while($it->valid()) {

                if ( !$it->isDot() ) {

                    $source = realpath($this->targetDir . '/' . $sourceDir . $it->getSubPathName());
                    $target = $this->targetDir . '/' . $it->getSubPathName();
                    $this->writeln("Symlinking '$source' to '$target'");

                    $fs->atomicSymlink($source, $target, true, $doOverwrite);
                }

                $it->next();
            }
        }
    }
}
