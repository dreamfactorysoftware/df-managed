<?php namespace DreamFactory\Managed\Enums;

use DreamFactory\Library\Utility\Enums\FactoryEnum;

/**
 * Constants for supported managed platforms
 */
class ManagedPlatforms extends FactoryEnum
{
    //******************************************************************************
    //* Constants
    //******************************************************************************

    /**
     * @type int
     */
    const DREAMFACTORY = 0;
    /**
     * @type int
     */
    const BLUEMIX = 1;
    /**
     * @type int
     */
    const PIVOTAL = 2;
    /**
     * @type int
     */
    const CLOUDFOUNDRY = 3;
}
