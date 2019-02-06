<?php
/**
 * Created by PhpStorm.
 * User: lazarrs
 * Date: 29.03.16
 * Time: 07:42
 */

namespace CRON\Behat;

use Behat\Gherkin\Node\TableNode;
use CRON\Behat\Service\SampleImageService;
use PHPUnit_Framework_Assert as Assert;

trait NeosTrait
{

    protected $context = [];

    /**
     * Get the context for the specific workspace. Subsequent calls will retrieve the same instance
     *
     * @param string $workspaceName workspace name, defaults to 'live'
     *
     * @return \TYPO3\Neos\Domain\Service\ContentContext
     */
    protected function getContext($workspaceName = 'live')
    {

        if (!isset($this->context[$workspaceName])) {
            /** @var \TYPO3\Neos\Domain\Repository\SiteRepository $siteRepository */
            $siteRepository = $this->objectManager->get(\TYPO3\Neos\Domain\Repository\SiteRepository::class);

            /** @var \TYPO3\TYPO3CR\Domain\Service\ContextFactoryInterface $contextFactory */
            $contextFactory = $this->objectManager->get(\TYPO3\TYPO3CR\Domain\Service\ContextFactoryInterface::class);
            $this->context[$workspaceName] = $contextFactory->create([
                'currentSite' => $siteRepository->findFirstOnline(),
                'invisibleContentShown' => true,
                'workspaceName' => $workspaceName,
            ]);
        }

        return $this->context[$workspaceName];
    }

    /** @var \TYPO3\TYPO3CR\Domain\Model\NodeInterface */
    protected $node = null;
    /** @var string */
    protected $nodeIdentifier = null;
    protected $nodeWorkspaceName = null;

    /**
     * @return \TYPO3\TYPO3CR\Domain\Model\NodeInterface
     */
    protected function getNode()
    {
        if ($this->node === null && $this->nodeIdentifier !== null && $this->nodeWorkspaceName !== null) {
            $this->node = $this->getContext($this->nodeWorkspaceName)->getNodeByIdentifier($this->nodeIdentifier);
        }

        return $this->node;
    }

    protected function setNode(\TYPO3\TYPO3CR\Domain\Model\NodeInterface $node)
    {
        $this->node = $node;
        $this->nodeIdentifier = $node->getIdentifier();
        $this->nodeWorkspaceName = $node->getWorkspace()->getName();
    }

    protected $nodeTypeManager;

    /**
     * @return \TYPO3\TYPO3CR\Domain\Service\NodeTypeManager
     */
    protected function getNodeTypeManager()
    {
        if ($this->nodeTypeManager === null) {

            $this->nodeTypeManager = $this->objectManager->get(\TYPO3\TYPO3CR\Domain\Service\NodeTypeManager::class);
        }

        return $this->nodeTypeManager;
    }

    /**
     * Gets an existing node or page on path
     *
     * @param $path string absolute path, relative to /sites/my-site-name, e.g. /home
     * @param string $workspace workspace name, defaults to 'live'
     *
     * @return \TYPO3\TYPO3CR\Domain\Model\NodeInterface
     */
    protected function getNodeForPath($path, $workspace = 'live')
    {
        $context = $this->getContext($workspace);
        $path = strpos('/sites', $path) === 0 ? $path : $context->getCurrentSiteNode()->getPath() . $path;

        return $context->getNode($path);
    }

    protected function persist()
    {
        $this->getSubcontext('flow')->persistAll();
        $this->resetNodeInstances();
        $this->node = null;
        $this->context = [];
    }

    /**
     * Map a String Value to the corresponding Neos Object
     *
     * @param $propertyName string
     * @param $stringInput string
     *
     * @return mixed
     */
    protected function propertyMapper($propertyName, $stringInput)
    {

        if ($stringInput === 'NULL') {
            return null;
        }

        switch ($this->getNode()->getNodeType()->getConfiguration('properties.' . $propertyName . '.type')) {

            case 'references':
                $value = array_map(function ($path) { return $this->getNodeForPath($path); },
                    preg_split('/,\w*/', $stringInput));
                break;

            case 'reference':
                $value = $this->getNodeForPath($stringInput);
                break;

            case 'DateTime':
                $value = new \DateTime($stringInput);
                break;

            case 'integer':
                $value = intval($stringInput);
                break;

            case 'boolean':
                $value = boolval($stringInput);
                break;

            case \TYPO3\Media\Domain\Model\ImageInterface::class:
                if ($stringInput) {
                    /** @var SampleImageService $imageService */
                    $imageService = $this->objectManager->get(SampleImageService::class);
                    $value = $imageService->getSampleImage($stringInput);
                } else {
                    $value = null;
                }

                break;

            default:
                $value = $stringInput;
                break;
        }

        return $value;
    }

    /**
     * @When /^I set the page properties:$/
     */
    public function iSetThePageProperties(TableNode $table)
    {
        Assert::assertNotNull($this->getNode(), 'Current node is NULL');
        foreach ($table->getRows() as $row) {
            list($propertyName, $propertyValue) = $row;
            $value = $this->propertyMapper($propertyName, $propertyValue);
            $this->getNode()->setProperty($propertyName, $value);
        }

        $this->persist();
        $this->clearContentCache();
    }

    /**
     * @Given /^I create a new Page "([^"]*)" of type "([^"]*)" on path "([^"]*)"$/
     */
    public function iCreateANewPageOfTypeOnPath($title, $type, $path)
    {
        $this->iCreateANewPageOfTypeOnPathInWorkspace($title, $type, $path, 'live');
    }

    /**
     * @Given /^I create a new Page "([^"]*)" of type "([^"]*)" on path "([^"]*)" in workspace "([^"]*)"$/
     */
    public function iCreateANewPageOfTypeOnPathInWorkspace($title, $type, $path, $workspace)
    {
        $type = $this->getNodeTypeManager()->getNodeType($type);
        $folder = $this->getNodeForPath($path, $workspace);
        $this->setNode($folder->createNode($title, $type));
        Assert::assertNotNull($this->node);
        $this->persist();
    }

    /**
     * @Given /^I should have a Page of type "([^"]*)" on path "([^"]*)"$/
     */
    public function iShouldHaveAPageOfTypeOnPath($nodeType, $path)
    {
        $node = $this->getNodeForPath($path);
        Assert::assertNotNull($node);
        Assert::assertTrue($node->getNodeType()->isOfType($nodeType));
        $this->setNode($node);
    }

    /**
     * @Then /^I should get the page properties:$/
     */
    public function iShouldGetThePageProperties(TableNode $table)
    {
        Assert::assertNotNull($this->getNode());
        foreach ($table->getRows() as $row) {
            list($propertyName, $propertyValue) = $row;
            Assert::assertTrue($this->getNode()->hasProperty($propertyName));
            $expectedValue = $this->propertyMapper($propertyName, $propertyValue);
            Assert::assertEquals($this->getNode()->getProperty($propertyName), $expectedValue);
        }
    }

    /**
     * @When /^I (un|)hide the Page$/
     */
    public function iHideThePage($unhide)
    {
        Assert::assertNotNull($this->getNode());
        $this->getNode()->setHidden(!$unhide);
        $this->persist();
    }

    /**
     * @When /^I move the Page into "([^"]*)"$/
     */
    public function iMoveThePageInto($path)
    {
        Assert::assertNotNull($this->getNode());
        $moveInto = $this->getNodeForPath($path);
        Assert::assertNotNull($moveInto, 'target path cannot be resolved');
        $this->getNode()->moveInto($moveInto);
        $this->persist();
    }

    /**
     * @Given /^I wait (\d+) second(?:|s)$/
     */
    public function iWaitSecond($seconds)
    {
        sleep($seconds);
    }

    /**
     * @Given /^I publish the current workspace$/
     */
    public function iPublishTheCurrentWorkspace()
    {
        Assert::assertNotNull($this->nodeWorkspaceName, 'no current workspace set');
        $liveWorkspace = $this->getContext()->getWorkspace();
        $this->getContext($this->nodeWorkspaceName)->getWorkspace()->publish($liveWorkspace);
        $this->persist();
    }

    /**
     * @Given /^the page should be visible$/
     */
    public function thePageShouldBeVisible()
    {
        Assert::assertTrue($this->getNode()->isVisible());
    }

    /**
     * @Given /^the page should not be visible$/
     */
    public function thePageShouldNotBeVisible()
    {
        Assert::assertFalse($this->getNode()->isVisible());
    }
}
