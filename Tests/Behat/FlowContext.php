<?php

namespace CRON\Behat;

require_once(__DIR__ . '/../../../../Application/Neos.Behat/Tests/Behat/FlowContext.php');

/**
 * Created by PhpStorm.
 * User: lazarrs
 * Date: 12.03.16
 * Time: 09:22
 */
class FlowContext extends \Neos\Behat\Tests\Behat\FlowContext
{

    /**
     * @AfterSuite
     */
    public static function shutdownFlow()
    {
        if (self::$bootstrap !== null) {
            // WORKAROUND: don't call shutdown() to workaround Doctrine\ORM\ORMInvalidArgumentException
            // A detached entity was found during removed..
            //self::$bootstrap->shutdown('Runtime');
        }
    }

}
