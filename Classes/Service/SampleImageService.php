<?php
/**
 * Created by PhpStorm.
 * User: remuslazar
 * Date: 03.05.16
 * Time: 13:56
 */

namespace CRON\Behat\Service;

use /** @noinspection PhpUnusedAliasInspection */
    Neos\Flow\Annotations as Flow;

    use Neos\Media\Domain\Model\Image;

/**
 * @Flow\Scope("singleton")
 */
class SampleImageService
{

    /**
     * @Flow\Inject
     * @var \Neos\Flow\ResourceManagement\ResourceManager
     */
    protected $resourceManager;

    /**
     * Inject PersistenceManagerInterface
     *
     * @Flow\Inject
     * @var \Neos\Flow\Persistence\PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var \Neos\Media\Domain\Repository\ImageRepository
     */
    protected $imageRepository;

    /**
     * @param string $name image name (from Resources/Images)
     *
     * @return \Neos\Media\Domain\Model\ImageInterface
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
