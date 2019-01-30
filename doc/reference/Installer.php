<?php
/**
 * Commands designed to be run on Composer installations/upgrades of an eZ5 site.
 * They are usually activated in the main composer.json file
 *
 * The main idea is that settings and extensions for the Legacy Stack are stored in some specific directories
 * under the current project root, and these commands take care of deploying them (via symlinks) to the proper place
 *
 * @todo on windows, before trying to create symlinks, check if we are running elevated:
 *       exec 'whoami /groups | find "S-1-16-12288"' and look for non empty output
 */

namespace XXX\Bundle\DevtoolsBundle\Composer;

use Composer\Script\CommandEvent;
use Composer\Script\Event;

class Installer
{

    /**
     * Creates cache and log folders (eZP5) and checks for presence of mandatory folders as defined in composer.json file
     * @param Event $event
     * @throws \Exception
     *
     * @todo add windows support
     */
    public static function check( Event $event )
    {

        $io = $event->getIO();
        $io->write( "\n" . __METHOD__ . "\nDoing simple folder existence check" );

        $baseDir =  getcwd();

        $cacheDir = $baseDir . "/ezpublish/cache";
        if ( !is_dir( $cacheDir ) )
        {
            mkdir( $cacheDir );
            /// @todo test retcode
            exec( "chmod -R 777 " . escapeshellarg( $cacheDir ), $output, $retcode );
        }

        $logsDir = $baseDir . "/ezpublish/logs";
        if ( !is_dir( $logsDir ) )
        {
            mkdir( $logsDir );
            $output = array();
            /// @todo test retcode
            exec( "chmod -R 777 " . escapeshellarg( $logsDir ), $output, $retcode );
        }

        $requiredFolders = self::getSetting( 'required-folders', $event, array() );
        foreach( $requiredFolders as $folder => $msg )
        {
            if ( !file_exists( $baseDir . "/" . $folder ) )
            {
                throw new \Exception( "Missing required folder $folder. $msg" );
            }
            else
            {
                $io->write( "Required folder: " . $folder . " is present" );
            }
        }

        $io->write( "Folder existence check complete.\n" );
    }

    /**
     * Handle (install) symfony assets and legacy assets via "console" commands
     * @param Event $event
     *
     * @todo allow a dynamic way of finding path to php
     */
    public static function assets( Event $event )
    {
        $io = $event->getIO();
        $io->write( "\n" . __METHOD__ . "\nDo symlinking of assets both for symfony and legacy ez.\n(if you need to use a specific Sf environment for this, set SYMFONY_ENV env variable)" );

        $baseDir =  getcwd();

        $commands = array();
        $commands[] = "php " . escapeshellarg( "$baseDir/ezpublish/console" ) . " assets:install --symlink web";
        if ( !self::is_link( $baseDir . "/web/var" ) )
            $commands[] = "php " . escapeshellarg( "$baseDir/ezpublish/console" ) . " ezpublish:legacy:assets_install --symlink web";

        foreach( $commands as $command )
        {
            $io->write( "Running: " . $command );
            $output = array();
            /// @todo test retcode
            exec( $command, $output, $retcode );
        }

        $io->write( "Asset symlinks done.\n" );
    }

    /**
     * Handle legacy settings (symlink them to ezpublish_legacy from source folders)
     * @param Event $event
     * @throws \Exception
     */
    public static function legacySettings( Event $event )
    {
        $io = $event->getIO();
        $io->write( "\n" . __METHOD__ . "\nSymlinking settings to ezpublish_legacy folder" );

        $baseDir = getcwd();

        $legacyPath = $baseDir . "/" . self::getLegacyPath( $event );
        $legacySettingsPath = $legacyPath . "/settings";
        $projectSettingsPath = $baseDir . "/" . self::getSetting( 'legacy-settings-dir', $event, 'legacy/settings' );

        if ( !is_dir( $legacySettingsPath ) )
            throw new \Exception( "We are missing the eZ legacy settings dir. Move legacy code to ezpublish_legacy folder" );

        if ( file_exists( $projectSettingsPath . "/override") && !self::is_link( $legacySettingsPath . "/override") )
        {
            if ( !rename( $legacySettingsPath . "/override", $legacySettingsPath . "/override.bak" ) )
                throw new \Exception("Settings/override in ezpublish_legacy folder exists and is not a symlink");
        }

        /// @todo check if symlink exists and has wrong target...

        if ( file_exists( $projectSettingsPath . "/override") && !self::is_link( $legacySettingsPath . "/override" ) )
        {
            $io->write( "Symlinking 'override' settings" );
            symlink( $projectSettingsPath . "/override", $legacySettingsPath . "/override" );
	    }

        # Todo - Handle better for initial setup
        $i = 0;
        $siteaccesses = glob( $projectSettingsPath . "/siteaccess/*" );
        foreach( $siteaccesses as $siteaccess )
        {
            // skip files in there!
            if ( !is_dir( $siteaccess ) )
                continue;

            $i++;
            $symlink = $legacySettingsPath . "/siteaccess/" . basename( $siteaccess );

            if ( file_exists( $symlink ) && !self::is_link( $symlink ) )
            {
                if ( !rename( $symlink, $symlink . ".bak" ) )
                    throw new \Exception( "Siteaccess ". basename( $siteaccess ) . " in ezpublish_legacy folder exists and is not a symlink" );
            }

            if( !self::is_link( $symlink ) )
            {
                $io->write( "Symlinking siteaccess: " . basename( $siteaccess ) );
                symlink( $siteaccess, $symlink );
            }
            else
            {
                $io->write( "Siteaccess: " . basename( $siteaccess ) . " is already a symlink" );
            }
        }

        $io->write( "Symlink of legacy settings completed ($i siteaccesses found).\n" );
    }

    /**
     * Handle legacy extensions (symlink them to ezpublish_legacy from source folders)
     * @param Event $event
     * @throws \Exception
     */
    public static function legacyExtensions( Event $event )
    {
        $io = $event->getIO();
        $io->write( "\n" . __METHOD__ . "\nSymlinking extensions to ezpublish_legacy folder" );

        $baseDir = getcwd();

        $projectLegacyPath = $baseDir . "/legacy";
        $legacyPath = $baseDir . "/" . self::getLegacyPath( $event );
        $legacyExtensionPath = $legacyPath . "/extension";
        $projectExtensionPath = $baseDir . "/" . self::getSetting( 'legacy-extensions-dir', $event, 'legacy/extension' );

        if ( !is_dir( $legacyExtensionPath ) )
            throw new \Exception("We are missing the eZ legacy extension dir. Move legacycode to ezpublish_legacy folder");

        $i = 0;
        $extensions = glob( $projectExtensionPath . "/*");
        foreach( $extensions as $extension )
        {
            // skip files in there!
            if ( !is_dir( $extension ) )
                continue;
            $i++;

            $symlink = $legacyExtensionPath . "/" . basename( $extension );

            if ( file_exists( $symlink ) && !self::is_link( $symlink ) )
            {
                if ( !rename( $symlink, $symlink . ".bak" ) )
                    throw new \Exception( "Extension ". basename( $extension ) . " in ezpublish_legacy folder exists and is not a symlink" );
            }

            if ( !self::is_link( $symlink ) )
            {
                $io->write( "Symlinking extension: " . basename( $extension ) );
                symlink($extension, $symlink);
            }
            else
            {
                $io->write( "Extension: " . basename( $extension ) . " is already a symlink" );
            }
        }

        $io->write( "Symlink of legacy extensions completed ($i extensions found).\n" );
    }

    /**
     * Handle external legacy extensions by downloading directly from git into legacy/repos + simlinking to ezpublish_legacy
     *
     * This is to be superseded over time by using https://github.com/ezsystems/ezpublish-legacy-extension-installer
     * and giving each legacy extension its own github repo.
     *
     * @param Event $event
     * @throws \Exception
     */
    public static function extraLegacyExtensions( Event $event )
    {
        $io = $event->getIO();
        $io->write( "\n" . __METHOD__ . "\nFetching and updating extra legacy extensions" );

        $currentDir = getcwd();
        $extraExtensionsDir =  $currentDir . "/" . self::getSetting( 'extra-extensions-dir', $event, 'legacy/repos' );
        $legacyPath = $currentDir . "/" . self::getLegacyPath( $event );
        $legacyExtensionDir = realpath( $legacyPath . "/extension" );

        chdir( $legacyExtensionDir );

        $extensions = self::getSetting( 'extensions', $event, array() );
        foreach( $extensions as $extensionName => $extension )
        {
            $src = $extension['src'];
            $io->write( "Handling: " . $src );

            $branch = isset( $extension['branch'] ) ? $extension['branch'] : "master";
            $repoDir = $legacyExtensionDir;
            $currentExtensionDir = $repoDir . "/" . $extensionName;

            if ( isset( $extension['extension-path'] ) )
            {
                $repoDir = $extraExtensionsDir;
                $currentExtensionDir = $repoDir . "/" . $extensionName;
                if ( !is_dir( $repoDir ) )
                    mkdir( $repoDir );
                echo "Extracting to $repoDir\n";
            }

            if ( is_dir( $currentExtensionDir ) )
            {
                chdir( $currentExtensionDir );
                /// @todo check that this dir is correctly linked to good repo
                $io->write( " Set branch: $branch..." );
                /// @todo abort on error
                $output = array();
                exec( "git checkout " . escapeshellarg( $branch ), $output, $retcode );
                /// @todo abort on error
                $io->write( "  Pull ..." );
                $output = array();
                exec( "git pull", $output, $retcode );
            }
            else
            {
                chdir( $repoDir );

                $io->write("  Clone ...");
                /// @bug we do not use escapeshellarg to allow stupid git cmd definition in json to work...
                /// @todo abort on error
                $output = array();
                exec( "git clone $src " . escapeshellarg( $extensionName ), $output, $retcode );
                //$extDir = ( isset( $repoDir ) ? $repoDir : $extensionDir ) . "/" . $extensionName;
                //$io->write( "--> " . $extDir );
                chdir( $extensionName );
                $io->write( "  Set branch: $branch..." );
                /// @todo abort on error
                $output = array();
                exec( "git checkout " . escapeshellarg( $branch ), $output, $retcode );
            }

            if ( isset( $extension['extension-path'] ) && $extension['extension-path'] != '__NO_SYMLINK__' )
            {
                // we extracted in a separate folder: need to add symlinks
                // NB: this works as long as this repo contains extensions as 1st level folders

                //$io->write( $extensionPathDir );
                $extensionList = glob( $currentExtensionDir . '/'. $extension['extension-path'] . "/*" );
                foreach( $extensionList as $ext )
                {
                    if ( !is_dir( $ext ) )
                        continue;

                    $io->write( "  Found extension: " . basename( $ext ) );

                    $symlink = $legacyExtensionDir . "/" . basename( $ext );

                    /// @todo test that symlink target does not exist already
                    if ( file_exists( $symlink ) && !self::is_link( $symlink ) )
                    {
                        if ( !rename( $symlink, $symlink . ".bak" ) )
                        {
                            chdir( $currentDir );
                            throw new \Exception( "Extension ". basename($ext) . " in ezpublish_legacy folder exists and is not a symlink" );
                        }
                    }

                    if ( !self::is_link( $symlink ) )
                    {
                        $io->write( "  Symlink: $ext" . "->" . $symlink );
                        symlink( $ext, $symlink );
                    }
                    else
                    {
                        $io->write( "  Extension: " . basename( $ext ) . " is already a symlink" );
                    }
                }

            }

        }

        chdir( $currentDir );
        $io->write( "Done fetching/updating extra legacy extensions.\n" );
    }

    /**
     * Handle autoloading  of the old stuff (regenerate autoload conf)
     * @param Event $event
     *
     * @todo catch any errors and warn user
     */
    public static function legacyAutoload( Event $event )
    {
        $io = $event->getIO();
        $io->write( "\n" . __METHOD__ . "\nRegenerating legacy autoload" );

        $currentDir = getcwd();
        $baseDir = $currentDir;

        $legacyPath = $baseDir . "/" . self::getLegacyPath( $event );
        chdir( $legacyPath );
        /// @todo test retcode
        exec( "php bin/php/ezpgenerateautoloads.php", $output, $retcode );

        chdir( $currentDir );
        $io->write( "autoload done\n" );
    }

    /**
     * Takes any siteaccess which exists in ezpublish_legacy and is not a symlink and pushes it back to "legacy"
     * @param Event $event
     */
    public static function moveSiteaccesses( Event $event )
    {
        $io = $event->getIO();
        $io->write( "\n" . __METHOD__ . "\nMoving non-symlinked siteaccesses from ezpublish_legacy to legacy folder" );

        $baseDir = getcwd();
        $projectSaDir = $baseDir . "/" . self::getSetting( 'legacy-settings-dir', $event, 'legacy/settings' ) ."/siteaccess";
        $legacySaDir = $baseDir . "/" . self::getLegacyPath( $event ) . "/settings/siteaccess";

        $i = 0;
        $siteaccesses = glob( $legacySaDir . "/*" );
        foreach( $siteaccesses as $sa )
        {
            if ( is_dir( $sa ) && !self::is_link( $sa ) )
            {
                $i++;

                /// @todo test if target exists already and if it's writeable
                $newSa = $projectSaDir . "/" . basename( $sa );
                $io->write( "Moving $sa -> $newSa" );
                rename( $sa, $newSa );
                $io->write( "Symlinking back $newSa -> $sa" );
                symlink( $newSa, $sa );
            }
        }

        $io->write( "Siteaccesses moved ($i found)\n" );
    }

    /**
     * Takes config.php and siblings in "legacy" folder and symlinks them to ezpublish_legacy
     * @param Event $event
     * @throws \Exception
     */
    public static function legacyConfigFiles( Event $event )
    {
        $io = $event->getIO();
        $io->write( "\n" . __METHOD__ . "\nSymlinking config.php file(s) to legacy folder" );

        $baseDir = getcwd();

        $legacyPath = $baseDir . "/" . self::getLegacyPath( $event );
        $legacyTargetDir = $legacyPath;
        $legacyConfigDir = self::getSetting( 'legacy-configfiles-dir', $event, 'legacy' );

        $i = 0;
        $configs = glob( $legacyConfigDir . "/config*" );
        foreach( $configs as $c )
        {
            if ( is_dir( $c ) )
                continue;
            $i++;

            $symlink = $legacyTargetDir . "/" . basename( $c );

            if ( file_exists( $symlink ) && !self::is_link( $symlink ) )
            {
                if ( !rename( $symlink, $symlink . ".bak" ) )
                    throw new \Exception( "Config file ". basename($c) . " in ezpublish_legacy folder exists and is not a symlink" );
            }

            if  (!self::is_link( $symlink ) )
            {
                $io->write( "Symlinking config file: " . basename( $c ) );
                symlink($c, $symlink);
            }
            else
            {
                $io->write( "Config file: " . basename( $c ) . " is already a symlink" );
            }
        }

        $io->write( "Symlink config.php done ($i files found)\n" );
    }

    /**
     * Takes patch file found in "patches" and applies them
     * @param Event $event
     * @throws \Exception
     * @todo allow a dynamic way of finding path to 'patch' command
     */
    public static function patches( Event $event )
    {
        $io = $event->getIO();
        $io->write( "\n" . __METHOD__ . "\nApplying patches" );

        $baseDir = getcwd();

        //$legacyPath = $baseDir . "/" . self::getLegacyPath( $event );
        //$legacyTargetDir = $legacyPath;
        $patchDir = $baseDir . "/patches";

        $i = 0;
        $j = 0;
        $patches = glob( $patchDir . "/patch*", GLOB_ONLYDIR );
        foreach( $patches as $p )
        {

            $cmd = 'patch -p0';
            if ( true )
            {
                $cmd .= ' --dry-run';
            }

            $diffs = glob( $p . "/*.diff" );
            foreach( $diffs as $d )
            {
                if ( !is_file( $d ) )
                {
                    continue;
                }
                $i++;


                $io->write( "Applying patch file: " . basename( $d ) );
                $output = array();
                exec( $cmd . ' --dry-run < ' . escapeshellarg( $d ), $output, $retcode );
                if( $retcode )
                {
                    $j++;
                    $io->write( "ERROR! Patch file does not apply cleanly" );
                }
                else
                {
                    $output = array();
                    exec( $cmd . ' < ' . escapeshellarg( $d ), $output, $retcode );
                    if( $retcode )
                    {
                        throw new \Exception( "ERROR!!! Patch file $d did not apply cleanly!\nPlease review and bring code back to a stable state" );
                    }
                }
            }
        }

        $io->write( "Applying patches done ($i diff files found, $j failed)\n" );
    }

    /**
     * Compile all templates for eZ4 - based on config in the "extras" section
     * @param Event $event
     */
    public static function legacyTemplates( Event $event )
    {
        $io = $event->getIO();
        $io->write( "\n" . __METHOD__ . "\nCompiling templates" );

        $currentDir = getcwd();
        $baseDir = $currentDir;

        $legacyPath = $baseDir . "/" . self::getLegacyPath( $event );
        chdir( $legacyPath );

        $i = 0;
        $siteaccesses = self::getSetting( 'siteaccesses', $event, array() );
        foreach( $siteaccesses as $siteaccess => $def )
        {
            $output = array();
            $args = '-s ' . escapeshellarg( $siteaccess );
            if ( isset( $def['www-dir'] ) && $def['www-dir'] != '' )
            {
                $args .= ' --www-dir=' . escapeshellarg( $def['www-dir'] );
            }
            if ( isset( $def['index-file'] ) && $def['index-file'] != '' )
            {
                $args .= ' --index-file=' . escapeshellarg( $def['index-file'] );
            }
            if ( isset( $def['access-path'] ) && $def['access-path'] != '' )
            {
                $args .= ' --access-path=' . escapeshellarg( $def['access-path'] );
            }
            $cmd = "php bin/php/eztc.php $args";
            $io->write( "Running: " . $cmd );
            exec( $cmd, $output, $retcode );
            $i++;
        }

        chdir( $currentDir );
        $io->write( "Compiling templates done for $i siteaccesses\n" );
    }

    public static function checkConfigFiles( Event $event )
    {
        $io = $event->getIO();
        $io->write( "\n" . __METHOD__ . "\nChecking presence of config files" );

        $currentDir = getcwd();
        $baseDir = $currentDir;
        $legacyPath = $baseDir . "/" . self::getLegacyPath( $event );

        $requiredFiles = self::getSetting( 'required-config-files', $event, array() );
        foreach( @$requiredFiles['eZ5'] as $file )
        {
            $files[] = $currentDir . '/' .$file;
        }
        foreach( @$requiredFiles['LS'] as $file )
        {
            $files[] = $legacyPath . '/' . $file;
        }
        foreach( $files as $file )
        {
            if( !is_file( $file ) )
            {
                $io->write( "WARNING: you are missing config file $file" );
                //throw new \Exception( "Missing required config file $file );
            }
        }

        $io->write( "Done checking presence of config files\n" );
    }

    public static function is_link( $path )
    {
        // work around php bug 65697: is_link does not return true for <JUNCTION> symlinks
        return is_link( $path ) ? true : file_exists( $path ) && (bool)( array_diff( stat( $path ), lstat( $path ) ) );
    }

    /**
     * Do basic file permission stuff
     * @param Event $event
     *
     */
    /*public static function cache(Event $event)
    {
        $io = $event->getIO();
        $io->write("\n" . __METHOD__ . "\nClear some caches");

        $currentDir = getcwd();
        $baseDir = $currentDir; //;realpath($event->getComposer()->getConfig()->get('vendor-dir') . "/../");

        $cacheDir = $baseDir . "/ezpublish/cache";
        if (!is_dir($cacheDir))
            mkdir($cacheDir);
        exec("chmod -R 777 $cacheDir");

        $logsDir = $baseDir . "/ezpublish/logs";
        if (!is_dir($logsDir))
            mkdir($logsDir);
        exec("chmod -R 777 $logsDir");

        chdir($currentDir . "/ezpublish_legacy");
        exec("php bin/php/ezcache.php --clear-all --purge");

        $io->write("Caches cleared.\n");
        chdir($currentDir);
    }*/


    /**
     * Try setting reasonable permissions for legacy engine.
     * @param Event $event
     *
     * @todo check isInteractive() on $io before asking questions to the user, use $io->ask()
     */
    /*public static function legacyPermissions(Event $event)
    {
        $io = $event->getIO();
        $io->write("\n" . __METHOD__ . "\nSet correct legacy filepermissions");

        $currentDir = getcwd();

        $apacheSettingsFile = $currentDir . "/.apache";

        if (is_file($apacheSettingsFile))
            $username = trim(file_get_contents($apacheSettingsFile));
        else {
            $io->write("What is your apache username: ");
            $handle = fopen ("php://stdin","r");
            $username = trim(preg_replace("/[\r\n]/","",fgets($handle)));
            file_put_contents($apacheSettingsFile, $username);
        }

        $currentDir = getcwd();
        $baseDir = $currentDir; //realpath($event->getComposer()->getConfig()->get('vendor-dir') . "/../");


        $cmd1 = "setfacl -R -m u:$username:rwx -m u:`whoami`:rwx $currentDir/ezpublish/{cache,logs,config} ezpublish_legacy/{design,extension,settings,var}";
        $io->write($cmd1);
        $status1 = exec($cmd1);

        $cmd2 = "setfacl -dR -m u:$username:rwx -m u:`whoami`:rwx $currentDir/ezpublish/{cache,logs,config} ezpublish_legacy/{design,extension,settings,var}";
        $io->write($cmd2);
        $status2 = exec($cmd2);

        if (!$status1 || !$status2){
            chdir($baseDir . "/ezpublish_legacy");
            exec("chmod -R a+rwx ../ezpublish/config design extension settings settings/override settings/siteaccess var var/cache var/cache/codepages var/cache/ini var/cache/template var/cache/texttoimage var/log var/storage");
            chdir($baseDir . "/legacy");
            exec("chmod -R a+rwx settings/override");

            chdir($currentDir);
            exec("chmod +a \"$username allow delete,write,append,file_inherit,directory_inherit\" ezpublish/{cache,logs,config} ezpublish_legacy/{design,extension,settings,var}");
            exec("chmod +a \"`whoami` allow delete,write,append,file_inherit,directory_inherit\" ezpublish/{cache,logs,config} ezpublish_legacy/{design,extension,settings,var}");
        }
        $io->write("Done setting filepermissions.\n");
        chdir($currentDir);

    }*/

    /**
     * Returns (relative) path to legacy ez installation, by default 'ezpublish-legacy'
     * @param $event
     * @return string
     */
    protected static function getLegacyPath( $event )
    {
        return self::getSetting( 'ezpublish-legacy-dir', $event, 'ezpublish-legacy' );
    }

    /**
     * Returns a value from composer.json "extra" section, using a default when that is not found
     * @param string $name
     * @param $event
     * @param mixed $default
     * @return mixed
     */
    protected static function getSetting( $name, $event, $default=null )
    {
        $extra = $event->getComposer()->getPackage()->getExtra();
        return isset( $extra[$name] ) ? $extra[$name] : $default;
    }
}