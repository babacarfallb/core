<?php

namespace Zemit\Core\Assets;

use Phalcon\Assets\Manager as AssetsManager;

class Manager extends AssetsManager
{
    
    /**
     * Version of your app (ex. 1.0.0)
     * @var string Set the version to be added in the asset path
     */
    private $_version;
    
    /**
     *
     * @var bool true to automatically add the file time to the asset path
     */
    private $_fileTime;
    
    /**
     * Minify Javascript
     * @var bool true automatically minify javascript files
     */
    private $_minifyJS;
    
    /**
     * Minify CSS
     * @var bool true automatically minify stylesheet files
     */
    private $_minifyCSS;
    
    /**
     * Force the version manually
     * Version will be added to the assets path
     *
     * @param string $version
     */
    public function setVersion($version)
    {
        $this->_version = $version;
    }
    
    /**
     * Get the version if forced
     * @return string Version
     */
    public function getVersion()
    {
        return $this->_version;
    }
    
    public function setFileTime($fileTime)
    {
        $this->_fileTime = $fileTime ? true : false;
    }
    
    public function getFileTime()
    {
        return $this->_fileTime ? true : false;
    }
    
    public function setMinifyJS($minifyJS)
    {
        $this->_minifyJS = $minifyJS ? true : false;
    }
    
    public function getMinifyJS()
    {
        return $this->_minifyJS ? true : false;
    }
    
    public function setMinifyCSS($minifyCSS)
    {
        $this->_minifyCSS = $minifyCSS ? true : false;
    }
    
    public function getMinifyCSS()
    {
        return $this->_minifyCSS ? true : false;
    }
    
    
    /**
     * @param null $collectionName
     *
     * @return string
     */
    public function outputJs($collectionName = 'js')
    {
        $collection = $this->exists($collectionName) ? $this->get($collectionName) : false;
        if ($collection) {
            $resources = $this->get($collectionName)->getResources();
            if ($resources) {
                foreach ($resources as $resource) {
                    
                    // Add version and filetime to the local resources only
                    if ($resource->getLocal()) {
                        $resource->setPath(self::_addVersionToPath($resource->getPath(), $this->getVersion(), $this->getFileTime()));
                    }
                }
            }
            return parent::outputJs($collectionName);
        }
    }
    
    /**
     * @param null $collectionName
     *
     * @return string
     */
    public function outputCss($collectionName = 'css')
    {
        $collection = $this->exists($collectionName) ? $this->get($collectionName) : false;
        if ($collection) {
            $ressources = $collection->getResources();
            if ($ressources) {
                foreach ($ressources as $ressource) {
                    if ($ressource->getLocal()) {
                        $ressource->setPath(self::_addVersionToPath($ressource->getPath(), $this->getVersion(), $this->getFileTime()));
                    }
                }
            }
            return parent::outputCss($collectionName);
        }
        
    }
    
    private static function _addVersionToPath($path, $version, $addFileMTimeToPath = false)
    {
        if ($addFileMTimeToPath) {
            $path = self::_addFileMtimeToPath($path);
        }
        if (!empty($version)) {
            $path = explode('.', $path);
            $ext = array_pop($path);
            $path = implode('.', $path) . '.' . $version . '.' . $ext;
        }
        return $path;
    }
    
    private static function _addFileMtimeToPath($filepath)
    {
        $path = $filepath;
        if (file_exists($filepath)) {
            $path = explode('.', $filepath);
            $ext = array_pop($path);
            $path = implode('.', $path) . '.' . date('Ymdhis', filemtime($filepath)) . '.' . $ext;
        }
        return $path;
    }
    
}