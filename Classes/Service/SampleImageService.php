<?php
/**
 * Created by PhpStorm.
 * User: remuslazar
 * Date: 03.05.16
 * Time: 13:56
 */

namespace CRON\Behat\Service;

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Media\Domain\Model\Image;

/**
 * @Flow\Scope("singleton")
 */
class SampleImageService
{

    /**
     * @Flow\Inject
     * @var \TYPO3\Flow\ResourceManagement\ResourceManager
     */
    protected $resourceManager;

    /**
     * Inject PersistenceManagerInterface
     *
     * @Flow\Inject
     * @var \TYPO3\Flow\Persistence\PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var \TYPO3\Media\Domain\Repository\ImageRepository
     */
    protected $imageRepository;

    /**
     * @param string $name image name (from Resources/Images)
     *
     * @return \TYPO3\Media\Domain\Model\ImageInterface
     */
    public function getSampleImage($name)
    {
        $query = $this->imageRepository->createQuery();
        $result = $query->matching($query->equals('resource.filename', $name))->execute();
        $image = null;
        if (!($result && ($image = $result->getFirst()))) {
            $image = new Image($this->resourceManager->importResource(sprintf('resource://CRON.Behat/Resources/Images/%s',
                $name)));
            $this->imageRepository->add($image);
            $this->persistenceManager->persistAll();
        }

        return $image;
    }

}
