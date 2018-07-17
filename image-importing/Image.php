<?php


namespace HPOLS\ImportProduct\Model;

use \Magento\Framework\App\Filesystem\DirectoryList;
use \Magento\MediaStorage\Helper\File\Storage\Database;
use \Magento\Catalog\Model\Product\Media\Config;
use \Magento\Catalog\Model\ResourceModel\Product\Gallery;
use \Magento\Framework\EntityManager\MetadataPool;
use \Magento\Indexer\Model\IndexerFactory;

class Image extends \Magento\Framework\Model\AbstractModel
{
    /**
     * @var DirectoryList
     */
    private $_directoryList;

    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    private $_productRepository;

    /**
     * @var \Magento\Catalog\Model\Product\Gallery\Processor
     */
    private $_processor;

    const DS = DIRECTORY_SEPARATOR;
    const NOT_SELECTED_IMAGE = 'no_selection';

    /**
     * @var \Magento\MediaStorage\Helper\File\Storage\Database
     */
    protected $fileStorageDb;

    /**
     * @var \Magento\Catalog\Model\Product\Media\Config
     */
    protected $mediaConfig;

    /**
     * @var \Magento\Framework\Filesystem\Directory\WriteInterface
     */
    protected $mediaDirectory;

    /**
     * Gallery resource
     *
     * @var \Magento\Catalog\Model\ResourceModel\Product\Gallery
     */
    protected $galleryResource;

    /**
     * Gallery resource
     *
     * @var \Magento\Framework\EntityManager\EntityMetadata
     */
    protected $metadata;

    /**
     * Indexer
     *
     * @var \Magento\Indexer\Model\IndexerFactory
     */
    protected $indexerFactory;

    /**
     * Private fields to record info
     *
     * @var $baseSku - simple product sku
     * @var $imageMap - records of orientations to original image urls
     * @var $selectedImages - records of orientations to image's temp URI of the simple product
     */
    private $baseSku = null;
    private $imageMap = array();
    private $selectedImages = array();

    /**
     * Image constructor.
     * @param DirectoryList $directoryList
     * @param \Magento\Catalog\Api\ProductRepositoryInterface $productRepository
     * @param \Magento\Catalog\Model\Product\Gallery\Processor $processor
     */
    public function __construct(
        \Magento\Framework\App\Filesystem\DirectoryList $directoryList,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \Magento\Catalog\Model\Product\Gallery\Processor $processor,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\MediaStorage\Helper\File\Storage\Database $fileStorageDb,
        Config $mediaConfig,
        Gallery $galleryResource,
        MetadataPool $metadataPool,
        IndexerFactory $indexerFactory
    )
    {
        $this->_directoryList = $directoryList;
        $this->_productRepository = $productRepository;
        $this->_processor = $processor;
        $this->fileStorageDb = $fileStorageDb;
        $this->mediaConfig = $mediaConfig;
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $this->galleryResource = $galleryResource;
        $this->metadata = $metadataPool->getMetadata(
            'Magento\Catalog\Api\Data\ProductInterface'
        );
        $this->indexerFactory = $indexerFactory;
    }

    /**
     * Import Images By Xml
     *
     * @param string $sku
     * @param object $images
     * @param array $skus # Introduced to avoid downloading same set of images multiple times
     *
     */
    public function importImageByXML($sku, $images, $skus = null)
    {
        echo "\n";
        printf("Start... sku: %s", $sku);
        $importImages = array();
        if (is_null($skus) || (!is_null($skus) && $sku == array_shift($skus))) {

            // initialising for each simple product sku
            if ($this->baseSku != $sku) {
                $this->baseSku = $sku;
                $this->imageMap = array();
                $this->selectedImages = array();
            }

            $imgUrl = array();

            $orientations = array('Center facing', 'Left facing', 'Right facing', 'Rear facing');

            foreach ($images->image as $image) {

                $_background = (string)$image->background;
                $_pixel_width = (int)$image->pixel_width;
                $_pixel_height = (int)$image->pixel_height;
                $_orientation = (string)$image->orientation;
                $_search_keyword = strtolower($image->search_keyword);

                if (!in_array($_background, ['Transparent', 'White'])
                    || $_pixel_width > 1700 || $_pixel_width < 400 || $_pixel_height > 1700
                ) {
                    continue;
                }

                if (!isset($imgUrl[$_orientation]) || !isset($imgUrl[$_orientation][$_pixel_width])) {
                    $imgUrl[$_orientation][$_pixel_width] = $image;
                } else {
                    if ($imgUrl[$_orientation][$_pixel_width]->search_keyword !== 'ecommerce') {
                        if ($_search_keyword && $_search_keyword == 'ecommerce') {
                            $imgUrl[$_orientation][$_pixel_width] = $image;
                        } else {
                            if ($imgUrl[$_orientation][$_pixel_width]->background == 'White' && $_background == 'Transparent') {
                                $imgUrl[$_orientation][$_pixel_width] = $image;
                            }
                        }
                    }
                }

                if (!in_array($_orientation, $orientations)) {
                    $orientations[] = $_orientation;
                }
            }

            foreach ($orientations as $orientation) {
                if (isset($imgUrl[$orientation])) {
                    $keys = array_keys($imgUrl[$orientation]);
                    $max = max($keys);
                    if (isset($imgUrl[$orientation][800])) {
                        $this->imageMap[$orientation] = trim($imgUrl[$orientation][800]->image_url_https);
                    } else {
                        $this->imageMap[$orientation] = trim($imgUrl[$orientation][$max]->image_url_https);
                    }
                }
            }

            $i = 1;
            echo "\n";
            echo "Downloading.";
            foreach ($this->imageMap as $orientation => $url) {
                $ext = strtolower(pathinfo($url, PATHINFO_EXTENSION));
                $fileDir = $this->_directoryList->getPath('media') . self::DS . 'import' . self::DS . 'products' . self::DS . 'images';
                if (!is_dir($fileDir)) {
                    mkdir($fileDir, 0777, true);
                    chmod($fileDir, 0777);
                }
                $newFileName = $fileDir . self::DS . $sku . '-' . $i . '.' . $ext;
                $newImg = $this->getRemoteImage($url, $fileDir, $newFileName);
                echo ".";
                if ($newImg) {
                    $importImages[$orientation] = $newImg;
                    $i++;
                }

            }

            $this->selectedImages = $importImages;
        } else {
            foreach($this->selectedImages as $orientation => $img) {
                // Convert image name for current sku
                $importImages[$orientation] = str_replace($this->baseSku, $sku, $img);

                // Copy original image to corresponding product image
                $baseSkuFileName = pathinfo($this->selectedImages[$orientation], PATHINFO_FILENAME);
                $oriFileName = pathinfo($this->imageMap[$orientation], PATHINFO_FILENAME);
                copy(str_replace($baseSkuFileName, $oriFileName, $this->selectedImages[$orientation]),
                    $importImages[$orientation]
                );
            }
        }

        if ($importImages) {
            $this->importProductImages($sku, $importImages);
        }
    }

    /**
     * Download remote image
     * @param \Magento\Catalog\Model\Product
     * @param array $images
     * @return bool
     */
    protected function importProductImages($sku, $images)
    {
        $product = $this->_productRepository->get($sku, false, \Magento\Store\Model\Store::DEFAULT_STORE_ID);

        /** @var $product \Magento\Catalog\Model\Product */
        if (!$product->getId()) {
            unset($product);
            return false;
        }

        echo "\n";
        echo "Updating images";
        $starttime = microtime(true);

        // Initialising media gallery, get image value_id(s) of video images if any
        $videoImageIds = $this->preProcessMediaGallery($product);

        $position = 0;
        foreach($images as $orientation => $image) {
            echo ".";
            $data = array();
            $fileName = $this->processGalleryImage($image);

            // Set image roles
            if (strtolower($orientation) == 'center facing') {
                $mediaAttributeCodes = array_keys($product->getMediaAttributes());

                $storeIds = $product->getStoreIds();
                $storeIds = array_merge(array(\Magento\Store\Model\Store::DEFAULT_STORE_ID), $storeIds);
                foreach ($storeIds as $storeId) {
                    $product->setStoreId($storeId);

                    // Assign image attribute roles for all the store views
                    foreach ($mediaAttributeCodes as $mediaAttributeCode) {
                        $product->addAttributeUpdate($mediaAttributeCode, $fileName, $storeId);
                    }
                }
            }

            // Save gallery images
            $data['value'] = $fileName;
            $data['attribute_id'] = $this->_processor->getAttribute()->getAttributeId();
            $data['media_type'] = 'image';

            $value_id = $this->galleryResource->insertGallery($data);
            $this->galleryResource->bindValueToEntity($value_id, $product->getData($this->metadata->getLinkField()));

            $data['value_id'] = $value_id;
            $data['label'] = $orientation;
            $data['position'] = $position++;
            $data['disabled'] = 0;
            $data[$this->metadata->getLinkField()] = (int)$product->getData($this->metadata->getLinkField());

            $this->galleryResource->insertGalleryValueInStore($data);
            unlink($image);
        }

        // Update existing video images
        $this->processExistingVideoImages($videoImageIds, $position);

        // Post processing media gallery
        $this->postProcessMediaGallery($product);

        $endtime = microtime(true);
        $timediff = round($endtime - $starttime);
        printf("Images imported successfully... in %s seconds.\n", $timediff);
    }

    /**
     * Process existing video images
     *
     * @param array $videoImageIds
     */
    private function processExistingVideoImages($videoImageIds, $position) {
        foreach ($videoImageIds as $videoImageId) {
            $videoImageInfo = $this->galleryResource->loadDataFromTableByValueId(
                Gallery::GALLERY_VALUE_TABLE,
                array($videoImageId)
            )[0];

            $record_id = $videoImageInfo['record_id'];
            $row_id = $videoImageInfo['row_id'];
            $store_id = $videoImageInfo['store_id'];

            $this->galleryResource->saveDataRow(
                Gallery::GALLERY_VALUE_TABLE,
                array(
                    'record_id' => $record_id,
                    'value_id' => (int) $videoImageId,
                    'store_id' => $store_id,
                    'row_id' => $row_id,
                    'position' => $position++,
                    'label' => 'Video image',
                    'disabled' => false,
                ),
                array(
                    'position',
                    'label',
                    'disabled',
                )
            );
        }
    }

    /**
     * Place image files to catalog/product
     *
     * @param $product
     */
    protected function processGalleryImage($file) {
        $file = $this->mediaDirectory->getRelativePath($file);
        if (!$this->mediaDirectory->isFile($file)) {
            throw new LocalizedException(__('The image does not exist.'));
        }

        $pathinfo = pathinfo($file);
        $imgExtensions = ['jpg', 'jpeg', 'gif', 'png'];
        if (!isset($pathinfo['extension']) || !in_array(strtolower($pathinfo['extension']), $imgExtensions)) {
            throw new LocalizedException(__('Please correct the image file type.'));
        }

        $fileName = \Magento\MediaStorage\Model\File\Uploader::getCorrectFileName($pathinfo['basename']);
        $dispretionPath = \Magento\MediaStorage\Model\File\Uploader::getDispretionPath($fileName);

        $fileName = $dispretionPath . '/' . $fileName;
        $fileName = $this->getUniqueFilename($fileName);
        $productFile = $this->mediaConfig->getMediaPath($fileName);

        try {
            $this->mediaDirectory->copyFile($file, $productFile);
            $this->fileStorageDb->saveFile($productFile);
        } catch (\Exception $e) {
            throw new LocalizedException(__('We couldn\'t move this file: %1.', $e->getMessage()));
        }

        return $fileName;
    }

    /**
     * Get unique file name - avoid fileStorageDb->getUniqueFilename() due to its poor performance
     *
     * @param $fileName
     */
    private function getUniqueFilename($fileName) {
        $pathinfo = pathinfo($fileName);
        return $pathinfo['dirname'] . self::DS . $pathinfo['filename'] . '_T' . time() . '.' . $pathinfo['extension'];
    }

    /**
     * Prepare for gallery updating
     *
     * @param $product
     */
    protected function preProcessMediaGallery($product)
    {
        $storeIds = $product->getStoreIds();
        $storeIds = array_merge($storeIds, array(\Magento\Store\Model\Store::DEFAULT_STORE_ID));

        foreach ($storeIds as $storeId) {
            $product->setStoreId($storeId);

            // Remove assigned image attribute roles
            $mediaAttributeCodes = array_keys($product->getMediaAttributes());
            foreach ($mediaAttributeCodes as $mediaAttributeCode) {
                $product->addAttributeUpdate($mediaAttributeCode, self::NOT_SELECTED_IMAGE, $storeId);
            }

            // Reset media gallery to use default value
            if ($storeId != \Magento\Store\Model\Store::DEFAULT_STORE_ID) {
                $imageIds = array_keys($product->getMediaGallery('images'));
                foreach ($imageIds as $imageId) {
                    $this->galleryResource->deleteGalleryValueInStore(
                        $imageId,
                        $product->getData($this->metadata->getLinkField()),
                        $storeId);
                }
            }
        }

        // Processing All Store View
        $existingImages = $this->galleryResource->loadProductGalleryByAttributeId(
            $product, $this->_processor->getAttribute()->getAttributeId()
        );
        $existingVideoImageIds = array();
        foreach ($existingImages as $existingImage) {
            if ($existingImage['media_type'] == 'image') {
                // Remove existing images
                $this->galleryResource->deleteGalleryValueInStore(
                    $existingImage['value_id'],
                    $product->getData($this->metadata->getLinkField()),
                    0
                );
                $this->galleryResource->deleteGallery($existingImage['value_id']);

                $this->mediaDirectory->delete($this->mediaConfig->getMediaPath($existingImage['file']));
            } else {
                // media_type is external-video
                $existingVideoImageIds[] = $existingImage['value_id'];
            }
        }
        return $existingVideoImageIds;
    }

    /**
     * Post-process gallery updating
     *
     * @param $product
     */
    protected function postProcessMediaGallery($product) {
        // Reindex
        $indexerIds = array('catalog_product_flat', 'catalogsearch_fulltext');
        $indexer = $this->indexerFactory->create();
        echo "\nReindexing...";
        foreach ($indexerIds as $indexerId) {
            $indexer->load($indexerId);
            if ($indexer->getStatus() != 'processing') {
                $indexer->reindexRow($product->getId());
            }
        }
        echo " done. \n";
    }

    /**
     * Download Remote image
     * @param $url
     * @param $filename
     * @param int $type
     * @param null $referer
     * @return null
     */
    public function getRemoteImage($url, $backDir, $filename, $type = 1, $referer = NULL)
    {
        if ($type) {

            $originalFilename = basename($url);
            $existingFile = $backDir . '/' . $originalFilename;

            if (!file_exists($existingFile)) {
                $referer = 'http://www.hpstore.cn';
                $ch = curl_init();
                if ($referer) {
                    curl_setopt($ch, CURLOPT_URL, $referer);
                    curl_setopt($ch, CURLOPT_REFERER, $referer);
                }
                $timeout = 20;
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);

                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

                $img = curl_exec($ch);
                curl_close($ch);

                $fp2 = @fopen($existingFile, 'w');
                fwrite($fp2, $img);
                fclose($fp2);
            }

            $im = false;
            if (file_exists($existingFile)) {
                switch (exif_imagetype($existingFile)) {
                    case IMAGETYPE_PNG:
                        $im = imagecreatefrompng($existingFile);
                        break;
                    case IMAGETYPE_GIF:
                        $im = imagecreatefromgif($existingFile);
                        break;
                    case IMAGETYPE_JPEG:
                        $im = imagecreatefromjpeg($existingFile);
                        break;
                }
            }

            if (!$im) {
                file_put_contents(dirname($backDir) . '/missed-images.txt', $url . "\n", 8);
                print_r('Image is invalid: ' . $url . "\n");
                return null;
            }
            copy($existingFile, $filename);

        } else {

            $originalFilename = basename($url);
            $existingFile = $backDir . '/' . $originalFilename;

            if (!file_exists($existingFile)) {
                $this->writeImage($url, $existingFile);
                //ob_start();
                //readfile($url);
                //$img = ob_get_contents();
                //ob_end_clean();
                //$fp2 = @fopen($existingFile, 'w');
                //fwrite($fp2, $img);
                //fclose($fp2);
            }

            $im = false;
            if (file_exists($existingFile)) {
                switch (exif_imagetype($existingFile)) {
                    case IMAGETYPE_PNG:
                        $im = imagecreatefrompng($existingFile);
                        break;
                    case IMAGETYPE_GIF:
                        $im = imagecreatefromgif($existingFile);
                        break;
                    case IMAGETYPE_JPEG:
                        $im = imagecreatefromjpeg($existingFile);
                        break;
                }
            }
            if (!$im) {
                print_r('Image is invalid: ' . $url . "\n");
                return null;
            }

            copy($existingFile, $filename);
        }

        return $filename;
    }

    public function getIcoUrl($url)
    {
        $icoDir = $this->_directoryList->getPath(DirectoryList::MEDIA) . '/wysiwyg/feature-icons';
        if (!is_dir($icoDir)) {
            mkdir($icoDir);
            chmod($icoDir, 0777);
        }
        $basename = pathinfo($url, PATHINFO_BASENAME);
        if (!file_exists($icoDir . '/' . $basename)) {
            $this->getRemoteImage($url, $icoDir, $icoDir . '/' . $basename);
        }
        $uri = '{{media url="wysiwyg/feature-icons/' . $basename . '"}}';
        return $uri;
    }

    private function writeImage($url, $targetFile)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        $img = curl_exec($ch);
        curl_close($ch);

        $fp2 = @fopen($targetFile, 'w');
        fwrite($fp2, $img);
        fclose($fp2);
    }
}