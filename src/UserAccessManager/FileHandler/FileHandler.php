<?php
/**
 * FileHandler.php
 *
 * The FileHandler class file.
 *
 * PHP versions 5
 *
 * @author    Alexander Schneider <alexanderschneider85@gmail.com>
 * @copyright 2008-2017 Alexander Schneider
 * @license   http://www.gnu.org/licenses/gpl-2.0.html  GNU General Public License, version 2
 * @version   SVN: $Id$
 * @link      http://wordpress.org/extend/plugins/user-access-manager/
 */
namespace UserAccessManager\FileHandler;

use UserAccessManager\Config\Config;
use UserAccessManager\Wrapper\Wordpress;

/**
 * Class FileHandler
 *
 * @package UserAccessManager\FileHandler
 */
class FileHandler
{
    /**
     * @var Wordpress
     */
    protected $_oWrapper;

    /**
     * @var Config
     */
    protected $_oConfig;

    /**
     * @var FileProtectionFactory
     */
    protected $_oFileProtectionFactory;

    /**
     * FileHandler constructor.
     *
     * @param Wordpress             $oWrapper
     * @param Config                $oConfig
     * @param FileProtectionFactory $oFileProtectionFactory
     */
    public function __construct(Wordpress $oWrapper, Config $oConfig, FileProtectionFactory $oFileProtectionFactory)
    {
        $this->_oWrapper = $oWrapper;
        $this->_oConfig = $oConfig;
        $this->_oFileProtectionFactory = $oFileProtectionFactory;
    }

    /**
     * Delivers the content of the requested file.
     *
     * @param string $sFile
     * @param bool   $blIsImage
     *
     * @return null
     */
    public function getFile($sFile, $blIsImage)
    {
        //Deliver content
        if (file_exists($sFile)) {
            $sFileName = basename($sFile);

            /*
             * This only for compatibility
             * mime_content_type has been deprecated as the PECL extension file info
             * provides the same functionality (and more) in a much cleaner way.
             */
            $sFileExt = strtolower(array_pop(explode('.', $sFileName)));
            $aMimeTypes = $this->_oConfig->getMimeTypes();

            if (function_exists('finfo_open')) {
                $sFileInfo = finfo_open(FILEINFO_MIME);
                $sFileMimeType = finfo_file($sFileInfo, $sFile);
                finfo_close($sFileInfo);
            } elseif (function_exists('mime_content_type')) {
                $sFileMimeType = mime_content_type($sFile);
            } elseif (isset($aMimeTypes[$sFileExt])) {
                $sFileMimeType = $aMimeTypes[$sFileExt];
            } else {
                $sFileMimeType = 'application/octet-stream';
            }

            header('Content-Description: File Transfer');
            header('Content-Type: '.$sFileMimeType);

            if ($blIsImage === false) {
                $sBaseName = str_replace(' ', '_', basename($sFile));
                header('Content-Disposition: attachment; filename="'.$sBaseName.'"');
            }

            header('Content-Transfer-Encoding: binary');
            header('Content-Length: '.filesize($sFile));

            if ($this->_oConfig->getDownloadType() === 'fopen'
                && $blIsImage === false
            ) {
                $oHandler = fopen($sFile, 'r');

                //TODO find better solution (prevent '\n' / '0A')
                ob_clean();
                flush();

                while (!feof($oHandler)) {
                    if (!ini_get('safe_mode')) {
                        set_time_limit(30);
                    }

                    echo fread($oHandler, 1024);
                }

                exit;
            } else {
                ob_clean();
                flush();
                readfile($sFile);
                exit;
            }
        } else {
            $this->_oWrapper->wpDie(TXT_UAM_FILE_NOT_FOUND_ERROR);
            return null;
        }
    }

    /**
     * Creates a protection file.
     *
     * @param string $sDir        The destination directory.
     * @param string $sObjectType The object type.
     */
    public function createFileProtection($sDir = null, $sObjectType = null)
    {
        $sDir = ($sDir === null) ? $this->_oConfig->getUploadDirectory() : $sDir;

        if ($sDir !== null) {
            if ($this->_oWrapper->isNginx() === true) {
                $this->_oFileProtectionFactory->createNginxFileProtection()->create($sDir, $sObjectType);
            } else {
                $this->_oFileProtectionFactory->createApacheFileProtection()->create($sDir, $sObjectType);
            }
        }
    }


    /**
     * Deletes the protection files.
     *
     * @param string $sDir The destination directory.
     */
    public function deleteFileProtectionFiles($sDir = null)
    {
        $sDir = ($sDir === null) ? $this->_oConfig->getUploadDirectory() : $sDir;

        if ($sDir !== null) {
            if ($this->_oWrapper->isNginx() === true) {
                $this->_oFileProtectionFactory->createNginxFileProtection()->delete($sDir);
            } else {
                $this->_oFileProtectionFactory->createApacheFileProtection()->delete($sDir);
            }
        }
    }
}