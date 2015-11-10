<?php namespace DreamFactory\Managed\Contracts;

interface ProvidesManagedDatabase
{
    //******************************************************************************
    //* Methods
    //******************************************************************************

    /**
     * Returns the database configuration for an instance
     *
     * @return array
     */
    public function getDatabaseConfig();
}
