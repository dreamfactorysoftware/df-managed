<?php namespace DreamFactory\Managed\Contracts;

interface AuditingAware
{
    //******************************************************************************
    //* Methods
    //******************************************************************************

    /**
     * Returns the database configuration for an instance
     *
     * @param array $data An array of key-value pairs sent in the audit packet
     *
     * @return array
     */
    public function setAuditData($data = []);
}
