<?php namespace DreamFactory\Managed\Contracts;

interface ProvidesManagedCache
{
    //******************************************************************************
    //* Methods
    //******************************************************************************

    /**
     * Returns instance's absolute cache path
     *
     * @return string
     */
    public function getCachePath();
}
