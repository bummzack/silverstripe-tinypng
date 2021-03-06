<?php

use Kinglozzer\TinyPng\Compressor;

class TinyPngImage extends Image implements Flushable
{
    /**
     * @config
     * @var bool Regenerates images if set to true. This is set by {@link flush()}
     */
    private static $flush = false;
    /**
     * @var boolean
     */
    protected $shouldCompress = false;
    /**
     * @var \Kinglozzer\TinyPng\Compressor
     */
    protected $compressor;

    /**
     * @param array|null $record
     * @param boolean $isSingleton
     * @param DataModel|null $model
     */
    public function __construct($record = null, $isSingleton = false, $model = null)
    {
        parent::__construct($record, $isSingleton, $model);

        $this->setCompressor(new Compressor($this->config()->tinypng_api_key));
    }

    /**
     * Triggered early in the request when someone requests a flush.
     */
    public static function flush()
    {
        self::$flush = true;
    }

    /**
     * @param boolean $shouldCompress
     * @return $this
     */
    public function setShouldCompress($shouldCompress)
    {
        $this->shouldCompress = $shouldCompress;
        return $this;
    }

    /**
     * @return boolean
     */
    public function getShouldCompress()
    {
        return $this->shouldCompress;
    }

    /**
     * @param \Kinglozzer\TinyPng\Compressor $compressor
     * @return $this
     */
    public function setCompressor(\Kinglozzer\TinyPng\Compressor $compressor)
    {
        $this->compressor = $compressor;
        return $this;
    }

    /**
     * @return \Kinglozzer\TinyPng\Compressor
     */
    public function getCompressor()
    {
        return $this->compressor;
    }

    /**
     * Return an image object representing the image in the given format.
     * This image will be generated using generateFormattedImage().
     * The generated image is cached, to flush the cache append ?flush=1 to your URL.
     *
     * Just pass the correct number of parameters expected by the working function
     *
     * @param string $format The name of the format.
     * @return Image_Cached
     */
    public function getFormattedImage($format)
    {
        $args = func_get_args();

        if ($this->ID && $this->Filename && Director::fileExists($this->Filename)) {
            $cacheFile = call_user_func_array(array($this, "cacheFilename"), $args);
            $fullPath = Director::baseFolder() . "/" . $cacheFile;

            if (! file_exists($fullPath) || self::$flush) {
                call_user_func_array(array($this, "generateFormattedImage"), $args);

                // If this image should be compressed, compress it now
                if ($this->getShouldCompress()) {
                    $compressor = $this->getCompressor();

                    try {
                        $compressor->compress($fullPath)->writeTo($fullPath);
                    } catch(Exception $e) {
                        // Log, but do nothing else, leave the uncompressed image in-place
                        SS_Log::log('Image compression failed: ' . $e->getMessage(), SS_Log::ERR);
                        Debug::message('Image compression failed: ' . $e->getMessage());
                    }

                    $this->shouldCompress = false; // Reset for subsequent manipulations on this image
                }
            }

            $cached = Injector::inst()->createWithArgs('Image_Cached', array($cacheFile));
            // Pass through the title so the templates can use it
            $cached->Title = $this->Title;
            // Pass through the parent, to store cached images in correct folder.
            $cached->ParentID = $this->ParentID;

            return $cached;
        }
    }

    /**
     * Require Image's table. As Injector will always load this class instead of
     * Image, Image may not get a table even when it needs one. For that reason,
     * we have to avoid Injector and call Image::requireTable() manually
     */
    public function requireTable()
    {
        static $required = false;

        // This method will be called for both Image and TinyPngImage, but we
        // only need to call requireTable() once
        if (!$required) {
            $img = new Image;
            $img->requireTable();
            $required = true;
        }

        parent::requireTable();
    }
}

class TinyPngImage_Cached extends TinyPngImage
{
    /**
     * Create a new cached image.
     * @param string $filename The filename of the image.
     * @param boolean $isSingleton This this to true if this is a singleton() object, a stub for calling methods.
     *                             Singletons don't have their defaults set.
     * @param Image $sourceImage The source image object
     */
    public function __construct($filename = null, $isSingleton = false, Image $sourceImage = null)
    {
        parent::__construct(array(), $isSingleton);
        if ($sourceImage) {
            // Copy properties from source image, except unsafe ones
            $properties = $sourceImage->toMap();
            unset($properties['RecordClassName'], $properties['ClassName']);
            $this->update($properties);
        }
        $this->ID = -1;
        $this->Filename = $filename;
    }

    /**
     * Override the parent's exists method becuase the ID is explicitly set to -1 on a cached image we can't use the
     * default check
     *
     * @return bool Whether the cached image exists
     */
    public function exists()
    {
        return file_exists($this->getFullPath());
    }

    /**
     * @return string
     */
    public function getRelativePath()
    {
        return $this->getField('Filename');
    }

    /**
     * Prevent creating new tables for the cached record
     * @return false
     */
    public function requireTable()
    {
        return false;
    }

    /**
     * Prevent writing the cached image to the database
     * @throws Exception
     */
    public function write($showDebug = false, $forceInsert = false, $forceWrite = false, $writeComponents = false)
    {
        throw new Exception("{$this->ClassName} can not be written back to the database.");
    }
}
