<?php

namespace Kinglozzer\SilverStripeTinyPng;


use Exception;
use Intervention\Image\Image as InterventionImage;
use Psr\Log\LoggerInterface;
use SilverStripe\Assets\Image_Backend;
use SilverStripe\Assets\InterventionBackend;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Dev\Debug;

/**
 * Extension that adds the `Compressed` method to Image and DBFile.
 * Provides image compression through the TinyPNG API: https://tinypng.com/developers
 * @package Kinglozzer\SilverStripeTinyPng
 */
class ImageExtension extends Extension
{
    use Injectable;
    use Configurable;

    private static $compressor = null;

    private static $dependencies = [
        'logger' => '%$Psr\Log\LoggerInterface',
    ];

    /**
     * The logger instance.
     * This will be set automatically via dependency injection, as long as ProcessQueue is instantiated via Injector
     * @var LoggerInterface
     */
    public $logger;

    /**
     * Apply compression to the file at hand
     * @return DBFile The manipulated file
     */
    public function Compressed(){
        $variant = $this->owner->variantName(__FUNCTION__, 'x');
        return $this->owner->manipulateImage(
            $variant,
            function (Image_Backend $backend)  {
                /** @var InterventionImage $resource */
                $resource = $backend->getImageResource();
                if (!($backend instanceof InterventionBackend)) {
                    $this->logger->error('Cannot compress image due to incompatible image backend');
                    return $resource;
                }

                // Get the Intervention Image manager
                $manager = $backend->getImageManager();
                try {
                    \Tinify\setKey($this->config()->tinypng_api_key);
                    if (!$resource->isEncoded()) {
                        $resource->encode();
                    }
                    $resultData = \Tinify\fromBuffer($resource->getEncoded())->toBuffer();
                    $compressed = $manager->make($resultData);
                } catch(Exception $e) {
                    $this->logger->error('Image compression failed', ['exception' => $e]);
                    Debug::message('Image compression failed: ' . $e->getMessage());
                    return null;
                }

                return $backend->setImageResource($compressed);
            }
        );
    }
}
