<?php
/**
 * Created by PhpStorm.
 * User: remuslazar
 * Date: 16.03.16
 * Time: 10:35
 */

namespace CRON\Behat;

use Neos\Utility\Arrays;
use Behat\Gherkin\Node\TableNode;
use PHPUnit_Framework_Assert as Assert;

//require_once(__DIR__ . '/../../../../../../Application/Flowpack.Behat/Tests/Behat/FlowContext.php');
require_once(__DIR__ . '/FlowContext.php');
require_once(__DIR__ . '/NeosTrait.php');
require_once(__DIR__ . '/../../../../Framework/Neos.Flow/Tests/Behavior/Features/Bootstrap/IsolatedBehatStepsTrait.php');
require_once(__DIR__ . '/../../../../Framework/Neos.Flow/Tests/Behavior/Features/Bootstrap/SecurityOperationsTrait.php');

if (file_exists(__DIR__ . '/../../../../Application/TYPO3.TYPO3CR/Tests/Behavior/Features/Bootstrap/NodeOperationsTrait.php')) {
    require_once(__DIR__ . '/../../../../Application/TYPO3.TYPO3CR/Tests/Behavior/Features/Bootstrap/NodeOperationsTrait.php');
} else {
    require_once(__DIR__ . '/../../../../Neos/TYPO3.TYPO3CR/Tests/Behavior/Features/Bootstrap/NodeOperationsTrait.php');
}

/**
 * Class FeatureContextBase
 *
 * @package CRON\DazSite\Tests\Behat
 *
 * This class implements some basic NEOS Backend steps and should be extended by the specific FeatureContext
 *
 */
class FeatureContextBase extends \Behat\MinkExtension\Context\MinkContext
{

    use \TYPO3\TYPO3CR\Tests\Behavior\Features\Bootstrap\NodeOperationsTrait;
    use \Neos\Flow\Tests\Behavior\Features\Bootstrap\IsolatedBehatStepsTrait;
    use \Neos\Flow\Tests\Behavior\Features\Bootstrap\SecurityOperationsTrait;
    use NeosTrait;

    /**
     * @var string
     */
    protected $behatTestHelperObjectName = \Neos\Neos\Tests\Functional\Command\BehatTestHelper::class;

    /**
     * @var \Neos\Flow\ObjectManagement\ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @return \Neos\Flow\ObjectManagement\ObjectManagerInterface
     */
    protected function getObjectManager()
    {
        return $this->objectManager;
    }

    /**
     * Initializes the context
     *
     * @param array $parameters Context parameters (configured through behat.yml)
     */
    public function __construct(array $parameters)
    {
        $this->useContext('flow', new FlowContext($parameters));
        $this->objectManager = $this->getSubcontext('flow')->getObjectManager();
        $this->setupSecurity();
    }

    /**
     * Reset the content dimensions configuration
     * Note: this is very important, the value being set to 'default' => 'mul_ZZ' for Behat scenarios in
     * NodeOperationsTrait.php.
     *
     * @return void
     * @throws \Exception
     */
    public function resetContentDimensions()
    {
        if ($this->isolated === true) {
            $this->callStepInSubProcess(__METHOD__);
        } else {
            $contentDimensionRepository = $this->getObjectManager()->get(\TYPO3\TYPO3CR\Domain\Repository\ContentDimensionRepository::class);
            /** @var \TYPO3\TYPO3CR\Domain\Repository\ContentDimensionRepository $contentDimensionRepository */

            // Set the content dimensions to a fixed value for Behat scenarios
            $contentDimensionRepository->setDimensionsConfiguration([]);
        }
    }

    /**
     * @Given /^I imported the site "([^"]*)"$/
     */
    public function iImportedTheSite($packageKey)
    {
        // run the nodeindex:build to create the Elasticsearch index, if missing
        $this->iRunNodeindex();

        /** @var \Neos\Neos\Domain\Service\SiteImportService $siteImportService */
        $siteImportService = $this->objectManager->get(\Neos\Neos\Domain\Service\SiteImportService::class);
        $siteImportService->importFromPackage($packageKey);

        $this->getSubcontext('flow')->persistAll();
        $this->resetNodeInstances();
    }

    /**
     * @Given /^I run nodeindex:build$/
     */
    public function iRunNodeindex()
    {
        /** @var \Neos\Neos\Domain\Service\SiteImportService $siteImportService */
        /** @var \Flowpack\ElasticSearch\ContentRepositoryAdaptor\Command\NodeIndexCommandController $nodeIndexCommandController */
        $nodeIndexCommandController = $this->objectManager->get(\Flowpack\ElasticSearch\ContentRepositoryAdaptor\Command\NodeIndexCommandController::class);
        $nodeIndexCommandController->buildCommand();
        $nodeIndexCommandController->cleanupCommand();
    }

    /**
     * Clear the code cache.
     *
     * @BeforeScenario @clearcodecache
     */
    public function clearCodeCache()
    {
        $directories = array_merge(
            glob(FLOW_PATH_DATA . 'Temporary/Development/SubContextBehat/Cache')
        );
        if (is_array($directories)) {
            foreach ($directories as $directory) {
                \Neos\Utility\Files::removeDirectoryRecursively($directory);
            }
        }
    }

    /**
     * Clear the content cache. Since this could be needed for multiple Flow contexts, we have to do it on the
     * filesystem for now. Using a different cache backend than the FileBackend will not be possible with this approach.
     *
     * @BeforeScenario @fixtures
     */
    public function clearContentCache()
    {
        $directories = array_merge(
            glob(FLOW_PATH_DATA . 'Temporary/*/Cache/Data/TYPO3_TypoScript_Content'),
            glob(FLOW_PATH_DATA . 'Temporary/*/*/Cache/Data/TYPO3_TypoScript_Content')
        );
        if (is_array($directories)) {
            foreach ($directories as $directory) {
                \Neos\Utility\Files::removeDirectoryRecursively($directory);
            }
        }
    }

    /**
     * @Given /^I am authenticated with "([^"]*)" and "([^"]*)" for the backend$/
     */
    public function iAmAuthenticatedWithAndForTheBackend($username, $password)
    {
        $this->visit('/neos/login');
        $this->fillField('Username', $username);
        $this->fillField('Password', $password);
        $this->pressButton('Login');
    }

    /**
     * @Given /^the following users exist:$/
     */
    public function theFollowingUsersExist(TableNode $table)
    {
        $rows = $table->getHash();
        /** @var \Neos\Neos\Domain\Service\UserService $userService */
        $userService = $this->objectManager->get(\Neos\Neos\Domain\Service\UserService::class);
        /** @var \Neos\Party\Domain\Repository\PartyRepository $partyRepository */
        $partyRepository = $this->objectManager->get(\Neos\Party\Domain\Repository\PartyRepository::class);
        /** @var \Neos\Flow\Security\AccountRepository $accountRepository */
        $accountRepository = $this->objectManager->get(\Neos\Flow\Security\AccountRepository::class);
        foreach ($rows as $row) {
            $roleIdentifiers = array_map(function ($role) {
                return 'Neos.Neos:' . $role;
            }, Arrays::trimExplode(',', $row['roles']));
            if ($user = $userService->getUser($row['username'])) {
                $userService->deleteUser($user);
                $this->getSubcontext('flow')->persistAll();
            }
            $userService->createUser($row['username'], $row['password'], $row['firstname'], $row['lastname'],
                $roleIdentifiers);
        }
        $this->getSubcontext('flow')->persistAll();
    }

    /**
     * @param callable $callback
     * @param integer $timeout Timeout in milliseconds
     * @param string $message
     */
    public function spinWait($callback, $timeout, $message = '')
    {
        $waited = 0;
        while ($callback() !== true) {
            if ($waited > $timeout) {
                Assert::fail($message);

                return;
            }
            usleep(50000);
            $waited += 50;
        }
    }

    /**
     * @When /^I select the first headline content element$/
     */
    public function iSelectTheFirstHeadlineContentElement()
    {
        $element = $this->assertSession()->elementExists('css', 'h1.neos-inline-editable');
        $element->click();

        $this->selectedContentElement = $element;
    }


    private $selectedContentElement = null;

    /**
     * @Given /^I set the content to "([^"]*)"$/
     */
    public function iSetTheContentTo($content)
    {
        $editable = $this->assertSession()->elementExists('css', '.neos-inline-editable',
            $this->selectedContentElement);

        $this->getSession()->wait(2000);
        $this->spinWait(function () use ($editable) {
            return $editable->hasAttribute('contenteditable');
        }, 12000, 'editable has contenteditable attribute set');

        $editable->setValue($content);
    }

    /**
     * @Given /^I click the Publish button$/
     */
    public function iClickThePublishButton()
    {
        $this->getSession()->wait(3000);
        $button = $this->assertSession()->elementExists('css', 'button.neos-publish-button');
        $button->click();
        $this->getSession()->wait(3000);
    }

    /**
     * @Given /^I wait for the changes to be saved$/
     */
    public function iWaitForTheChangesToBeSaved()
    {
        $this->getSession()->wait(30000, '$(".neos-publish-menu-active").length > 0');
        $this->assertSession()->elementExists('css', '.neos-publish-menu-active');
        // after the publish button being active, wait some time for the AJAX request to finish
        $this->getSession()->wait(4000);
    }

    /**
     * @When /^I select the NEOS Inspector$/
     */
    public function iSelectTheNeosInspector()
    {
        // wait 30 seconds for the NEOS BE to appear
        $this->getSession()->wait(30000, '$("#neos-inspector").length > 0');
        $this->selectedContentElement = $this->assertSession()->elementExists('css', '.neos-inspector-form');
    }

    /**
     * @Given /^I open the Date Picker (\d+)$/
     */
    public function iOpenTheDatePicker($nth = 1)
    {
        // wait for the date picker to be fully loaded
        $this->getSession()->wait(10000, '$("#neos-inspector input.neos-editor-datetimepicker-hrvalue").length > 0');

        // scroll to it, if not visible yet (else we cannot click on it)
        $this->getSession()->executeScript('$("#neos-inspector input.neos-editor-datetimepicker-hrvalue")[' . ($nth-1) . '].scrollIntoView(true);');

        $this->assertSession()->elementExists('xpath', '(//div[@class="neos-inspector-datetime-editor"])[' . $nth . ']//input[contains(@class, "neos-editor-datetimepicker-hrvalue")]',
            $this->selectedContentElement)->click();

        // wait to fully expand
        $this->getSession()->wait(10000, '$("#neos-inspector div.neos-editor-datetimepicker").is(":visible")');
    }

    /**
     * @Given /^click on Today in the Date Picker (\d+)$/
     */
    public function clickOnTodayInTheDatePicker($nth = 1)
    {
        $this->getSession()->wait(10000, '$(".neos-datetimepicker-days .neos-today").is(":visible")');
        $this->assertSession()->elementExists('xpath', '(//div[contains(@class,"date-time-editor")])[' . $nth . ']//div[contains(@class,"neos-datetimepicker-days")]//th[contains(@class, "neos-today")]',
            $this->selectedContentElement)->click();
    }

    /**
     * @Given /^click Apply$/
     */
    public function clickApply()
    {
        $this->assertSession()->elementExists('css', '.neos-inspector-apply')->click();
//        $this->getSession()->wait(10000);
    }

    /**
     * @Then /^I should see "([^"]*)" in the (\d+)\. element having css class "(?P<element>[^"]*)"$/
     */
    public function iShouldSeeInTheElementHavingCssClass($text, $nth, $cssClass)
    {
        $element = sprintf("//*[@class='%s'][%d]", $cssClass, $nth);
        $this->assertSession()->elementTextContains('xpath', $element, $this->fixStepArgument($text));
    }

}
