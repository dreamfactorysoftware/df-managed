<?php namespace DreamFactory\Managed\Contracts;

interface ManagesInstances
{
    //******************************************************************************
    //* Methods
    //******************************************************************************

    /**
     * @return boolean
     */
    public function isManaged();
}
