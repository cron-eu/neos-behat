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
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Media\Domain\Model\ImageInterface;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContext;
use PHPUnit\Framework\Assert;

require_once(__DIR__ . '/../../../../Application/Neos.Behat/Tests/Behat/FlowContextTrait.php');
require_once(__DIR__ . '/../../../../Framework/Neos.Flow/Tests/Behavior/Features/Bootstrap/SecurityOperationsTrait.php');

trait NeosTrait
{
    use \Neos\Behat\Tests\Behat\FlowContextTrait;
    use \Neos\Flow\Tests\Behavior\Features\Bootstrap\SecurityOperationsTrait;

    /**
     * @var \Neos\Flow\ObjectManagement\ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var ContentContext[]
     */
    protected $context = [];

    /**
     * Get the context for the specific workspace. Subsequent calls will retrieve the same instance
     *
     * @param string $workspaceName workspace name, defaults to 'live'
     *
     * @return ContentContext
     */
    protected function getContext($workspaceName = 'live')
    {
        if (!isset($this->context[$workspaceName])) {
            /** @var SiteRepository $siteRepository */
            $siteRepository = $this->objectManager->get(SiteRepository::class);
            /** @var ContextFactoryInterface $contextFactory */
            $contextFactory = $this->objectManager->get(ContextFactoryInterface::class);
            $this->context[$workspaceName] = $contextFactory->create([
                'currentSite' => $siteRepository->findFirstOnline(),
                'invisibleContentShown' => true,
                'workspaceName' => $workspaceName,
            ]);
        }
        return $this->context[$workspaceName];
    }

    /** @var \Neos\ContentRepository\Domain\Model\NodeInterface */
    protected $node = null;

    /** @var string */
    protected $nodeIdentifier = null;

    /** @var string */
    protected $nodeWorkspaceName = null;

    /**
     * @return \Neos\ContentRepository\Domain\Model\NodeInterface
     */
    protected function getNode()
    {
        if ($this->node === null && $this->nodeIdentifier !== null && $this->nodeWorkspaceName !== null) {
            $this->node = $this->getContext($this->nodeWorkspaceName)->getNodeByIdentifier($this->nodeIdentifier);
        }

        return $this->node;
    }

    protected function setNode(\Neos\ContentRepository\Domain\Model\NodeInterface $node)
    {
        $this->node = $node;
        $this->nodeIdentifier = $node->getIdentifier();
        $this->nodeWorkspaceName = $node->getWorkspace()->getName();
    }

    /**
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @return NodeTypeManager
     */
    protected function getNodeTypeManager()
    {
        if ($this->nodeTypeManager === null) {
            $this->nodeTypeManager = $this->objectManager->get(NodeTypeManager::class);
        }

        return $this->nodeTypeManager;
    }

    /**
     * Gets an existing node or page on path
     *
     * @param $path string absolute or relative (to the current site root node) path.
     * @param string $workspace workspace name, defaults to 'live'
     *
     * @return \Neos\ContentRepository\Domain\Model\NodeInterface
     */
    protected function getNodeForPath($path, $workspace = 'live')
    {
        $context = $this->getContext($workspace);

        // if the path is absolute, use it "as is"
        if (strpos($path, '/sites') !== 0) {
            // strip the leading slash from the path
            $path = preg_replace('/^\//', '', $path);
            $pathComponents = [$context->getCurrentSiteNode()->getPath()];
            if ($path !== "") {
                $pathComponents[] = $path;
            }
            $path = join('/', $pathComponents);
        }
        return $context->getNode($path);
    }

    protected function persist()
    {
        $this->objectManager->get(PersistenceManagerInterface::class)->persistAll();
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

            case ImageInterface::class:
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
     * @When /^I set the node properties:$/
     */
    public function iSetTheNodeProperties(TableNode $table)
    {
        $this->securityContext->withoutAuthorizationChecks(function () use ($table) {
            Assert::assertNotNull($this->getNode());
            foreach ($table->getRows() as $row) {
                list($propertyName, $propertyValue) = $row;
                $value = $this->propertyMapper($propertyName, $propertyValue);
                $this->getNode()->setProperty($propertyName, $value);
            }
            $this->persist();
            $this->clearContentCache();
        });
    }

    /**
     * @Given /^I create a new Page "([^"]*)" of type "([^"]*)" on path "([^"]*)"$/
     * @Given /^I create a new node "([^"]*)" of type "([^"]*)" on path "([^"]*)"$/
     */
    public function iCreateANewNodeOfTypeOnPath($name, $type, $path)
    {
        $this->iCreateANewNodeOfTypeOnPathInWorkspace($name, $type, $path, 'live');
    }

    /**
     * @Given /^I create a new Page "([^"]*)" of type "([^"]*)" on path "([^"]*)" in workspace "([^"]*)"$/
     * @Given /^I create a new node "([^"]*)" of type "([^"]*)" on path "([^"]*)" in workspace "([^"]*)"$/
     */
    public function iCreateANewNodeOfTypeOnPathInWorkspace($name, $type, $path, $workspace)
    {
        $this->securityContext->withoutAuthorizationChecks(function () use ($type, $path, $workspace, $name) {
            $type = $this->getNodeTypeManager()->getNodeType($type);
            $folder = $this->getNodeForPath($path, $workspace);
            $this->setNode($folder->createNode($name, $type));
            Assert::assertNotNull($this->node);
            $this->persist();
        });
    }

    /**
     * @Given /^I should have a Page of type "([^"]*)" on path "([^"]*)"$/
     * @Given /^I should have a node of type "([^"]*)" on path "([^"]*)"$/
     */
    public function iShouldHaveANodeOfTypeOnPath($nodeType, $path)
    {
        $node = $this->getNodeForPath($path);
        Assert::assertNotNull($node);
        Assert::assertTrue($node->getNodeType()->isOfType($nodeType));
        $this->setNode($node);
    }

    /**
     * @Then /^I should get the page properties:$/
     * @Then /^I should get the node properties:$/
     */
    public function iShouldGetTheNodeProperties(TableNode $table)
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
        $this->securityContext->withoutAuthorizationChecks(function () {
            Assert::assertNotNull($this->nodeWorkspaceName, 'no current workspace set');
            $liveWorkspace = $this->getContext()->getWorkspace();
            $this->getContext($this->nodeWorkspaceName)->getWorkspace()->publish($liveWorkspace);
            $this->persist();
        });
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

    /**
     * @Given /^I set the node "([^"]*)" property to the current date$/
     */
    public function iSetTheNodePropertyToTheCurrentDate($propertyName)
    {
        $date = new \DateTime();
        // set 5 minutes ago, to prevent race conditions where the article not being shown in FE
        $date->sub(new \DateInterval('PT5M'));
        $this->iSetTheNodePropertyTo($propertyName, $date);
    }
    /**
     * @Given /^I set the node "([^"]*)" property to the date "([^"]*)"$/
     */
    public function iSetTheNodePropertyToTheGivenDate($propertyName, $dateString)
    {
        $date = new \DateTime($dateString);
        // set 5 minutes ago, to prevent race conditions where the article not being shown in FE
        $date->sub(new \DateInterval('PT5M'));
        $this->iSetTheNodePropertyTo($propertyName, $date);
    }

    /**
     * @Given /^I create an empty site with package key "([^"]*)" named "([^"]*)" with a root node of type "([^"]*)"$/
     */
    public function iCreateAnEmptySiteNamedWithARootNodeOfType($packageKey, $siteName, $rootNodeTypeName)
    {
        /** @var SiteRepository */
        $siteRepository = $this->objectManager->get(SiteRepository::class);
        $site = new Site($siteName);
        $site->setSiteResourcesPackageKey($packageKey);
        $site->setState(Site::STATE_ONLINE);
        $siteRepository->add($site);

        /** @var NodeTypeManager $nodeTypeManager */
        $nodeTypeManager = $this->objectManager->get(NodeTypeManager::class);
        $nodeType = $nodeTypeManager->getNodeType($rootNodeTypeName);

        $rootNode = $this->getContext()->getRootNode();
        $sitesNode = $rootNode->createNode('sites', $nodeTypeManager->getNodeType('unstructured'));
        $sitesNode->createNode($siteName, $nodeType);
        $this->persist();
    }

}
