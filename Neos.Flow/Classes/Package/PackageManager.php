<?php
namespace Neos\Flow\Package;

/*
 * This file is part of the Neos.Flow package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Composer\Exception\InvalidConfigurationException;
use Neos\Flow\Composer\ComposerUtility;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\SignalSlot\Dispatcher;
use Neos\Flow\SignalSlot\Exception\InvalidSlotException;
use Neos\Utility\Exception\FilesException;
use Neos\Utility\Files;
use Neos\Utility\OpcodeCacheHelper;
use Neos\Flow\Package\Exception as PackageException;
use Composer\Console\Application as ComposerApplication;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * The default Flow Package Manager
 *
 * @api
 * @Flow\Scope("singleton")
 */
class PackageManager
{
    /**
     * The current format version for PackageStates.php files
     */
    public const PACKAGESTATE_FORMAT_VERSION = 6;

    /**
     * The default package states
     */
    public const DEFAULT_PACKAGE_INFORMATION_CACHE_FILEPATH = FLOW_PATH_TEMPORARY_BASE . '/PackageInformationCache.php';

    /**
     * @var Bootstrap
     */
    protected $bootstrap;

    /**
     * @var PackageFactory
     */
    protected $packageFactory;

    /**
     * @var Dispatcher
     */
    protected $dispatcher;

    /**
     * Array of available packages, indexed by package key (case sensitive)
     *
     * @var array
     */
    protected $packages = [];

    /**
     * A translation table between lower cased and upper camel cased package keys
     *
     * @var array<string, string>
     */
    protected $packageKeys = [];

    /**
     * A map between ComposerName and PackageKey, only available when scanAvailablePackages is run
     *
     * @var array
     */
    protected $composerNameToPackageKeyMap = [];

    /**
     * Absolute path leading to the various package directories
     *
     * @var string
     */
    protected $packagesBasePath;

    /**
     * @var string
     */
    protected $packageInformationCacheFilePath;

    /**
     * Package states configuration as stored in the PackageStates.php file
     *
     * @var array
     */
    protected $packageStatesConfiguration = [];

    /**
     * @var array
     */
    protected $settings;

    /**
     * @var FlowPackageInterface[]
     */
    protected $flowPackages = [];

    /**
     * Inject settings into the package manager. Has to be called explicitly on object initialization as
     * the package manager subpackage is excluded from proxy class building.
     *
     * @param array $settings
     * @return void
     */
    public function injectSettings(array $settings): void
    {
        $this->settings = $settings['package'] ?? [];
    }

    /**
     * @param string $packageInformationCacheFilePath
     * @param string $packagesBasePath
     */
    public function __construct($packageInformationCacheFilePath, $packagesBasePath)
    {
        $this->packageFactory = new PackageFactory();
        $this->packagesBasePath = $packagesBasePath;
        $this->packageInformationCacheFilePath = $packageInformationCacheFilePath;
    }

    /**
     * Initializes the package manager
     *
     * @param Bootstrap $bootstrap The current bootstrap
     * @return void
     * @throws Exception
     * @throws Exception\CorruptPackageException
     * @throws FilesException
     * @throws InvalidConfigurationException
     */
    public function initialize(Bootstrap $bootstrap): void
    {
        $this->bootstrap = $bootstrap;
        $this->packageStatesConfiguration = $this->getCurrentPackageStates();
        $this->registerPackagesFromConfiguration($this->packageStatesConfiguration);
        /** @var PackageInterface $package */

        foreach ($this->packages as $package) {
            if ($package instanceof FlowPackageInterface) {
                $this->flowPackages[$package->getPackageKey()] = $package;
            }
            if (!$package instanceof BootablePackageInterface) {
                continue;
            }
            $package->boot($bootstrap);
        }
    }

    /**
     * Get only packages that implement the FlowPackageInterface for use in the Framework
     * Array keys will be the respective package keys.
     *
     * @return FlowPackageInterface[]
     * @internal
     */
    public function getFlowPackages(): array
    {
        return $this->flowPackages;
    }

    /**
     * Returns true if a package is available (the package's files exist in the packages directory)
     * or false if it's not.
     *
     * @param string $packageKey The key of the package to check
     * @return boolean true if the package is available, otherwise false
     * @api
     */
    public function isPackageAvailable($packageKey): bool
    {
        return ($this->getCaseSensitivePackageKey($packageKey) !== false);
    }

    /**
     * Returns the base path for packages
     *
     * @return string
     */
    public function getPackagesBasePath(): string
    {
        return $this->packagesBasePath;
    }

    /**
     * Returns a PackageInterface object for the specified package.
     *
     * @param string $packageKey
     * @return PackageInterface The requested package object
     * @throws Exception\UnknownPackageException if the specified package is not known
     * @api
     */
    public function getPackage($packageKey): PackageInterface
    {
        if (!$this->isPackageAvailable($packageKey)) {
            throw new Exception\UnknownPackageException('Package "' . $packageKey . '" is not available. Please check if the package exists and that the package key is correct (package keys are case sensitive).', 1166546734);
        }

        return $this->packages[$this->getCaseSensitivePackageKey($packageKey)];
    }

    /**
     * Returns an array of PackageInterface objects of all available packages.
     * A package is available, if the package directory contains valid meta information.
     *
     * @return array<PackageInterface>
     * @api
     */
    public function getAvailablePackages(): array
    {
        return $this->packages;
    }

    /**
     * Returns an array of PackageInterface objects of all packages that match
     * the given package state, path, and type filters. All three filters must match, if given.
     *
     * @param string $packageState defaults to available
     * @param string $packageType
     *
     * @return array<PackageInterface>
     * @throws Exception\InvalidPackageStateException
     * @api
     */
    public function getFilteredPackages($packageState = 'available', $packageType = null): array
    {
        switch (strtolower($packageState)) {
            case 'available':
                $packages = $this->getAvailablePackages();
                break;
            case 'frozen':
                throw new Exception\InvalidPackageStateException('The package state "frozen" is has been removed', 1739462316);
            default:
                throw new Exception\InvalidPackageStateException('The package state "' . $packageState . '" is invalid', 1372458274);
        }

        if ($packageType !== null) {
            $packages = $this->filterPackagesByType($packages, $packageType);
        }

        return $packages;
    }

    /**
     * Returns an array of PackageInterface objects in the given array of packages
     * that are of the specified package type.
     *
     * @param array $packages Array of PackageInterface objects to be filtered
     * @param string $packageType Filter out anything that's not of this packageType
     * @return array<PackageInterface>
     */
    protected function filterPackagesByType($packages, $packageType): array
    {
        $filteredPackages = [];
        foreach ($packages as $package) {
            if ($package->getComposerManifest('type') === $packageType) {
                $filteredPackages[$package->getPackageKey()] = $package;
            }
        }

        return $filteredPackages;
    }

    /**
     * Create a package, given the package key
     *
     * @param string $packageKey The package key of the new package
     * @param array $manifest A composer manifest as associative array.
     * @param string $packagesPath If specified, the package will be created in this path, otherwise the default "Application" directory is used
     * @return PackageInterface The newly created package
     *
     * @throws Exception
     * @throws Exception\CorruptPackageException
     * @throws Exception\InvalidPackageKeyException
     * @throws Exception\PackageKeyAlreadyExistsException
     * @throws FilesException
     * @throws InvalidConfigurationException
     * @api
     */
    public function createPackage($packageKey, array $manifest = [], $packagesPath = null): PackageInterface
    {
        $packageKey = FlowPackageKey::fromString($packageKey);
        if ($this->isPackageAvailable($packageKey->value)) {
            throw new Exception\PackageKeyAlreadyExistsException('The package key "' . $packageKey->value . '" already exists', 1220722873);
        }
        if (!isset($manifest['type'])) {
            $manifest['type'] = PackageInterface::DEFAULT_COMPOSER_TYPE;
        }

        $runComposerRequireForTheCreatedPackage = false;
        if ($packagesPath === null) {
            $composerManifestRepositories = ComposerUtility::getComposerManifest(FLOW_PATH_ROOT, 'repositories');
            if (is_array($composerManifestRepositories)) {
                foreach ($composerManifestRepositories as $repository) {
                    if (is_array($repository) &&
                        isset($repository['type']) && $repository['type'] === 'path' &&
                        isset($repository['url']) && substr($repository['url'], 0, 2) === './' && substr($repository['url'], -2) === '/*'
                    ) {
                        $packagesPath = Files::getUnixStylePath(Files::concatenatePaths([FLOW_PATH_ROOT, substr($repository['url'], 0, -2)]));
                        $runComposerRequireForTheCreatedPackage = true;
                        break;
                    }
                }
            }
        }

        if ($packagesPath === null) {
            $packagesPath = 'Application';
            if (is_array($this->settings['packagesPathByType']) && isset($this->settings['packagesPathByType'][$manifest['type']])) {
                $packagesPath = $this->settings['packagesPathByType'][$manifest['type']];
            }

            $packagesPath = Files::getUnixStylePath(Files::concatenatePaths([$this->packagesBasePath, $packagesPath]));
        }

        $packagePath = Files::concatenatePaths([$packagesPath, $packageKey->value]) . '/';
        Files::createDirectoryRecursively($packagePath);

        foreach (
            [
                FlowPackageInterface::DIRECTORY_CLASSES,
                FlowPackageInterface::DIRECTORY_CONFIGURATION,
                FlowPackageInterface::DIRECTORY_RESOURCES,
                FlowPackageInterface::DIRECTORY_TESTS_UNIT,
                FlowPackageInterface::DIRECTORY_TESTS_FUNCTIONAL,
            ] as $path) {
            Files::createDirectoryRecursively(Files::concatenatePaths([$packagePath, $path]));
        }

        $manifest = ComposerUtility::writeComposerManifest($packagePath, $packageKey, $manifest);

        if ($runComposerRequireForTheCreatedPackage) {
            $composerRequireArguments = new ArrayInput([
                'command' => 'require',
                'packages' => [$manifest['name'] . ' @dev'],
                '--working-dir' => FLOW_PATH_ROOT,
                '--quiet'
            ]);

            $composerApplication = new ComposerApplication();
            $composerApplication->setAutoExit(false);
            $composerErrorCode = $composerApplication->run($composerRequireArguments);

            if ($composerErrorCode !== 0) {
                throw new Exception("The installation was not successful. Composer returned the error code: $composerErrorCode", 1572187932);
            }
        }

        $refreshedPackageStatesConfiguration = $this->rescanPackages();
        $this->packageStatesConfiguration = $refreshedPackageStatesConfiguration;
        $this->registerPackageFromStateConfiguration($manifest['name'], $this->packageStatesConfiguration['packages'][$manifest['name']]);
        $package = $this->packages[$packageKey->value];
        if ($package instanceof FlowPackageInterface) {
            $this->flowPackages[$packageKey->value] = $package;
        }

        return $package;
    }

    /**
     * Rescans available packages, order and write a new PackageStates file.
     *
     * @return array The found and sorted package states.
     * @throws Exception
     * @throws InvalidConfigurationException
     * @throws FilesException
     * @api
     */
    public function rescanPackages(): array
    {
        $loadedPackageStates = $this->scanAvailablePackages();
        $loadedPackageStates = $this->sortAndSavePackageStates($loadedPackageStates);
        return $loadedPackageStates;
    }

    /**
     * Loads the states of available packages from the PackageStates.php file and
     * initialises a package scan if the file was not found or the configuration format
     * was not current.
     *
     * @return array
     * @throws Exception
     * @throws InvalidConfigurationException
     * @throws FilesException
     */
    protected function getCurrentPackageStates(): array
    {
        $savePackageStates = false;
        $loadedPackageStates = $this->loadPackageStates();
        if (
            empty($loadedPackageStates)
            || !isset($loadedPackageStates['version'])
            || $loadedPackageStates['version'] < self::PACKAGESTATE_FORMAT_VERSION
        ) {
            $loadedPackageStates = $this->scanAvailablePackages();
            $savePackageStates = true;
        }

        if ($savePackageStates) {
            $loadedPackageStates = $this->sortAndSavePackageStates($loadedPackageStates);
        }

        return $loadedPackageStates;
    }

    /**
     * Load the current package states
     *
     * @return array
     */
    protected function loadPackageStates(): array
    {
        return (is_file($this->packageInformationCacheFilePath) ? include $this->packageInformationCacheFilePath  : []);
    }

    /**
     * Scans all directories in the packages directories for available packages.
     * For each package a Package object is created and stored in $this->packages.
     *
     * @return array
     * @throws Exception
     * @throws InvalidConfigurationException
     */
    protected function scanAvailablePackages(): array
    {
        $newPackageStatesConfiguration = ['packages' => []];

        $packages = new \AppendIterator();
        $packages->append(new \NoRewindIterator(self::findComposerPackagesInPath($this->packagesBasePath)));

        foreach ($packages as $packagePath) {
            $composerManifest = ComposerUtility::getComposerManifest($packagePath);

            if (isset($composerManifest['type']) && $composerManifest['type'] === 'neos-package-collection') {
                // the package type "neos-package-collection" indicates a composer package that joins several "flow" packages together.
                // continue traversal inside the package and append the nested generator to the $packages.
                $packages->append(new \NoRewindIterator(self::findComposerPackagesInPath($packagePath)));
                continue;
            }

            if (!isset($composerManifest['name'])) {
                throw new InvalidConfigurationException(sprintf('A package composer.json was found at "%s" that contained no "name".', $packagePath), 1445933572);
            }

            if (strpos($packagePath, Files::concatenatePaths([$this->packagesBasePath, 'Inactive'])) === 0) {
                // Skip packages in legacy "Inactive" folder.
                continue;
            }

            $packageKey = FlowPackageKey::deriveFromManifestOrPath($composerManifest, $packagePath);
            $this->composerNameToPackageKeyMap[strtolower($composerManifest['name'])] = $packageKey->value;

            $packageConfiguration = $this->preparePackageStateConfiguration($packageKey, $packagePath, $composerManifest);
            if (isset($newPackageStatesConfiguration['packages'][$composerManifest['name']])) {
                throw new PackageException(
                    sprintf(
                        'The package with the name "%s" was found more than once, please make sure it exists only once. Paths "%s" and "%s".',
                        $composerManifest['name'],
                        $packageConfiguration['packagePath'],
                        $newPackageStatesConfiguration['packages'][$composerManifest['name']]['packagePath']
                    ),
                    1493030262
                );
            }

            $newPackageStatesConfiguration['packages'][$composerManifest['name']] = $packageConfiguration;
        }

        return $newPackageStatesConfiguration;
    }

    /**
     * Traverses directories recursively from the given starting point and yields folder paths, who contain a composer.json.
     * When a composer.json was found, traversing into lower directories is stopped.
     */
    protected function findComposerPackagesInPath(string $startingDirectory): \Generator
    {
        $directories = new \DirectoryIterator($startingDirectory);
        /** @var \DirectoryIterator $fileInfo */
        foreach ($directories as $fileInfo) {
            if ($fileInfo->isDot()) {
                continue;
            }
            if ($fileInfo->isDir() === false) {
                continue;
            }
            $potentialPackageDirectory = $fileInfo->getPathname() . '/';
            if (is_file($potentialPackageDirectory . 'composer.json') === false) {
                // no composer.json was found - search recursive for a composer.json
                yield from self::findComposerPackagesInPath($potentialPackageDirectory);
                continue;
            }
            yield Files::getUnixStylePath($potentialPackageDirectory);
        }
    }

    /**
     * @throws Exception\CorruptPackageException
     * @throws Exception\InvalidPackagePathException
     */
    protected function preparePackageStateConfiguration(FlowPackageKey $packageKey, string $packagePath, array $composerManifest): array
    {
        $autoload = $composerManifest['autoload'] ?? [];

        return [
            'packageKey' => $packageKey->value,
            'packagePath' => str_replace($this->packagesBasePath, '', $packagePath),
            'composerName' => $composerManifest['name'],
            'autoloadConfiguration' => $autoload,
            'packageClassInformation' => $this->packageFactory->detectFlowPackageFilePath($packageKey, $packagePath)
        ];
    }

    /**
     * Requires and registers all packages which were defined in packageStatesConfiguration
     *
     * @param array $packageStatesConfiguration
     * @throws Exception\CorruptPackageException
     */
    protected function registerPackagesFromConfiguration($packageStatesConfiguration): void
    {
        foreach ($packageStatesConfiguration['packages'] as $composerName => $packageStateConfiguration) {
            $this->registerPackageFromStateConfiguration($composerName, $packageStateConfiguration);
        }
    }

    /**
     * Registers a package under the given composer name with the configuration.
     * This uses the PackageFactory to create the Package instance and sets it
     * to all relevant data arrays.
     *
     * @param string $composerName
     * @param array $packageStateConfiguration
     * @return void
     * @throws Exception\CorruptPackageException
     */
    protected function registerPackageFromStateConfiguration($composerName, $packageStateConfiguration): void
    {
        $packagePath = $packageStateConfiguration['packagePath'] ?? null;
        $packageClassInformation = $packageStateConfiguration['packageClassInformation'] ?? null;
        $package = $this->packageFactory->create($this->packagesBasePath, $packagePath, FlowPackageKey::fromString($packageStateConfiguration['packageKey']), $composerName, $packageStateConfiguration['autoloadConfiguration'], $packageClassInformation);
        $this->packageKeys[strtolower($package->getPackageKey())] = $package->getPackageKey();
        $this->packages[$package->getPackageKey()] = $package;
    }

    /**
     * Takes the given packageStatesConfiguration, sorts it by dependencies, saves it and returns
     * the ordered list
     *
     * @param array $packageStates
     * @return array
     * @throws Exception\PackageStatesFileNotWritableException
     * @throws FilesException
     */
    protected function sortAndSavePackageStates(array $packageStates): array
    {
        $orderedPackageStates = $this->sortAvailablePackagesByDependencies($packageStates);
        $this->savePackageStates($orderedPackageStates);

        return $orderedPackageStates;
    }

    /**
     * Save the given (ordered) array of package states data
     *
     * @param array $orderedPackageStates
     * @throws Exception\PackageStatesFileNotWritableException
     * @throws FilesException
     */
    protected function savePackageStates(array $orderedPackageStates): void
    {
        $orderedPackageStates['version'] = static::PACKAGESTATE_FORMAT_VERSION;

        $fileDescription = "# PackageStates.php\n\n";
        $fileDescription .= "# This file is maintained by Flow's package management. You shouldn't edit it manually\n";
        $fileDescription .= "# manually, you should rather use the command line commands for maintaining packages.\n";
        $fileDescription .= "# You'll find detailed information about the neos.flow:package:* commands in their\n";
        $fileDescription .= "# respective help screens.\n\n";
        $fileDescription .= "# This file will be regenerated automatically if it doesn't exist. Deleting this file\n";
        $fileDescription .= "# should, however, never become necessary if you use the package commands.\n";

        $packageStatesCode = "<?php\n" . $fileDescription . "\nreturn " . var_export($orderedPackageStates, true) . ';';

        Files::createDirectoryRecursively(dirname($this->packageInformationCacheFilePath));
        $result = @file_put_contents($this->packageInformationCacheFilePath, $packageStatesCode);
        if ($result === false) {
            throw new Exception\PackageStatesFileNotWritableException(sprintf('Flow could not update the list of installed packages because the file %s is not writable. Please, check the file system permissions and make sure that the web server can write to it.', $this->packageInformationCacheFilePath), 1382449759);
        }
        // Clean legacy file TODO: Remove at some point
        $legacyPackageStatesPath = FLOW_PATH_CONFIGURATION . 'PackageStates.php';
        if (is_file($legacyPackageStatesPath)) {
            try {
                @unlink($legacyPackageStatesPath);
            } catch (\Throwable $e) {
                // PHP 8 apparently throws for unlink even with shutup operator, but we really don't care at this place. It's also the only way to handle this race-condition free.
            }
        }
        OpcodeCacheHelper::clearAllActive($this->packageInformationCacheFilePath);

        try {
            $this->emitPackageStatesUpdated();
        } catch (InvalidSlotException $e) {
        } catch (\Neos\Flow\Exception $e) {
        }
    }

    /**
     * Orders all packages by comparing their dependencies. By this, the packages
     * and package configurations arrays holds all packages in the correct
     * initialization order.
     *
     * @param array $packageStates The unordered package states
     * @return array ordered package states.
     */
    protected function sortAvailablePackagesByDependencies(array $packageStates): array
    {
        $packageOrderResolver = new PackageOrderResolver($packageStates['packages'], $this->collectPackageManifestData($packageStates));
        $packageStates['packages'] = $packageOrderResolver->sort();

        return $packageStates;
    }

    /**
     * Collects the manifest data for all packages in the given package states array
     *
     * @param array $packageStates
     * @return array
     */
    protected function collectPackageManifestData(array $packageStates): array
    {
        return array_map(function ($packageState) {
            return ComposerUtility::getComposerManifest(Files::getNormalizedPath(Files::concatenatePaths([$this->packagesBasePath, $packageState['packagePath']])));
        }, $packageStates['packages']);
    }

    /**
     * Returns the correctly cased version of the given package key or false
     * if no such package is available.
     *
     * @param string $unknownCasedPackageKey The package key to convert
     * @return string|false The upper camel cased package key or false if no such package exists
     * @api
     */
    public function getCaseSensitivePackageKey($unknownCasedPackageKey)
    {
        $lowerCasedPackageKey = strtolower($unknownCasedPackageKey);

        return $this->packageKeys[$lowerCasedPackageKey] ?? false;
    }

    /**
     * Resolves a Flow package key from a composer package name.
     *
     * @param string $composerName
     * @return string
     * @throws Exception\InvalidPackageStateException
     */
    public function getPackageKeyFromComposerName($composerName): string
    {
        if ($this->composerNameToPackageKeyMap === []) {
            foreach ($this->packageStatesConfiguration['packages'] as $packageStateConfiguration) {
                $this->composerNameToPackageKeyMap[$packageStateConfiguration['composerName']] = $packageStateConfiguration['packageKey'];
            }
        }

        $lowercasedComposerName = strtolower($composerName);
        if (!isset($this->composerNameToPackageKeyMap[$lowercasedComposerName])) {
            throw new Exception\InvalidPackageStateException('Could not find package with composer name "' . $lowercasedComposerName . '" in PackageStates configuration.', 1352320649);
        }

        return $this->composerNameToPackageKeyMap[$lowercasedComposerName];
    }

    /**
     * Check the conformance of the given package key
     *
     * @param string $packageKey The package key to validate
     * @return boolean If the package key is valid, returns true otherwise false
     * @api
     */
    public function isPackageKeyValid($packageKey): bool
    {
        return FlowPackageKey::isPackageKeyValid($packageKey);
    }

    /**
     * Emits a signal when package states have been changed (e.g. when a package was created)
     *
     * The advice is not proxyable, so the signal is dispatched manually here.
     *
     * @return void
     * @throws \Neos\Flow\Exception
     * @throws InvalidSlotException
     * @Flow\Signal
     */
    protected function emitPackageStatesUpdated(): void
    {
        if ($this->bootstrap === null) {
            return;
        }

        if ($this->dispatcher === null) {
            $this->dispatcher = $this->bootstrap->getEarlyInstance(Dispatcher::class);
        }

        $this->dispatcher->dispatch(self::class, 'packageStatesUpdated');
    }
}
