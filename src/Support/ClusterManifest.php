<?php namespace DreamFactory\Managed\Support;

use DreamFactory\Library\Utility\JsonFile;
use DreamFactory\Managed\Enums\ManagedDefaults;
use DreamFactory\Managed\Exceptions\ManagedEnvironmentException;
use DreamFactory\Managed\Services\ClusterService;
use Illuminate\Support\Collection;

class ClusterManifest extends Collection
{
    //******************************************************************************
    //* Members
    //******************************************************************************

    /**
     * @type ClusterService
     */
    protected $cluster;

    //******************************************************************************
    //* Methods
    //******************************************************************************

    /**
     * @param ClusterService $cluster
     */
    public function __construct(ClusterService $cluster)
    {
        parent::__construct();

        $this->cluster = $cluster;
        $this->load();
    }

    /**
     * Locate and load the cluster manifest
     *
     * @throws \DreamFactory\Managed\Exceptions\ManagedEnvironmentException
     */
    protected function load()
    {
        if (false === ($_file = $this->locateClusterEnvironmentFile())) {
            throw new ManagedEnvironmentException('No cluster manifest file was found.');
        }

        try {
            $_manifest = JsonFile::decodeFile($_file);
            $this->validateManifest($_manifest);

            foreach ($_manifest as $_key => $_value) {
                $this->put($_key, $_value);
            }
        } catch (\InvalidArgumentException $_ex) {
            throw new ManagedEnvironmentException('The cluster manifest file is corrupt, invalid, or otherwise unreadable.');
        }
    }

    /**
     * Locate the configuration file for DFE, if any
     *
     * @param string $file
     *
     * @return bool|string
     */
    protected function locateClusterEnvironmentFile($file = ManagedDefaults::CLUSTER_MANIFEST_FILE)
    {
        $_path = isset($_SERVER, $_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : getcwd();

        while (true) {
            if (file_exists($_path . DIRECTORY_SEPARATOR . $file)) {
                return $_path . DIRECTORY_SEPARATOR . $file;
            }

            $_parentPath = dirname($_path);

            if ($_parentPath == $_path || empty($_parentPath) || $_parentPath == DIRECTORY_SEPARATOR) {
                return false;
            }

            $_path = $_parentPath;
        }

        return false;
    }

    /**
     * Validates the data pulled from the manifest
     *
     * @param array $manifest
     *
     * @throws \DreamFactory\Managed\Exceptions\ManagedEnvironmentException
     */
    protected function validateManifest(&$manifest = [])
    {
        //  Can we build the API url
        if (empty($_url = array_get($manifest, 'console-api-url')) || !array_get($manifest, 'console-api-key')) {
            throw new ManagedEnvironmentException('"console-api-url" and/or "console-api-key" are missing or invalid');
        }

        //  Ensure trailing slash on console-api-url
        array_set($manifest, 'console-api-url', rtrim($_url, '/ ') . '/');

        //  Ensure all dots are gone from default-domain
        if (!empty($_defaultDomain = trim(array_get($manifest, 'default-domain'), '. '))) {
            $_defaultDomain = '.' . $_defaultDomain;
            array_set($manifest, 'default-domain', $_defaultDomain);
        }

        array_set($manifest,
            'instance-name',
            str_replace($_defaultDomain, null, $_host = $this->cluster->getHostName()));

        //  Make sure we have a storage root
        if (empty($storageRoot = array_get($manifest, 'storage-root'))) {
            throw new ManagedEnvironmentException('No "storage-root" found.');
        }

        //  Clean the root...
        array_set($manifest, 'storage-root', rtrim($storageRoot, ' ' . DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }
}
