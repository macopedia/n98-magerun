<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\DependencyResolver;

use Composer\Package\PackageInterface;
use Composer\Package\AliasPackage;
use Composer\Package\LinkConstraint\VersionConstraint;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class DefaultPolicy implements PolicyInterface
{
    public function versionCompare(PackageInterface $a, PackageInterface $b, $operator)
    {
        $constraint = new VersionConstraint($operator, $b->getVersion());
        $version = new VersionConstraint('==', $a->getVersion());

        return $constraint->matchSpecific($version);
    }

    public function findUpdatePackages(Pool $pool, array $installedMap, PackageInterface $package)
    {
        $packages = array();

        foreach ($pool->whatProvides($package->getName()) as $candidate) {
            if ($candidate !== $package) {
                $packages[] = $candidate;
            }
        }

        return $packages;
    }

    public function getPriority(Pool $pool, PackageInterface $package)
    {
        return $pool->getPriority($package->getRepository());
    }

    public function selectPreferedPackages(Pool $pool, array $installedMap, array $literals)
    {
        $packages = $this->groupLiteralsByNamePreferInstalled($pool, $installedMap, $literals);

        foreach ($packages as &$literals) {
            $policy = $this;
            usort($literals, function ($a, $b) use ($policy, $pool, $installedMap) {
                return $policy->compareByPriorityPreferInstalled($pool, $installedMap, $pool->literalToPackage($a), $pool->literalToPackage($b), true);
            });
        }

        foreach ($packages as &$literals) {
            $literals = $this->pruneToBestVersion($pool, $literals);

            $literals = $this->pruneToHighestPriorityOrInstalled($pool, $installedMap, $literals);

            $literals = $this->pruneRemoteAliases($pool, $literals);
        }

        $selected = call_user_func_array('array_merge', $packages);

        // now sort the result across all packages to respect replaces across packages
        usort($selected, function ($a, $b) use ($policy, $pool, $installedMap) {
            return $policy->compareByPriorityPreferInstalled($pool, $installedMap, $pool->literalToPackage($a), $pool->literalToPackage($b));
        });

        return $selected;
    }

    protected function groupLiteralsByNamePreferInstalled(Pool $pool, array $installedMap, $literals)
    {
        $packages = array();
        foreach ($literals as $literal) {
            $packageName = $pool->literalToPackage($literal)->getName();

            if (!isset($packages[$packageName])) {
                $packages[$packageName] = array();
            }

            if (isset($installedMap[abs($literal)])) {
                array_unshift($packages[$packageName], $literal);
            } else {
                $packages[$packageName][] = $literal;
            }
        }

        return $packages;
    }

    public function compareByPriorityPreferInstalled(Pool $pool, array $installedMap, PackageInterface $a, PackageInterface $b, $ignoreReplace = false)
    {
        if ($a->getRepository() === $b->getRepository()) {
            // prefer aliases to the original package
            if ($a->getName() === $b->getName()) {
                $aAliased = $a instanceof AliasPackage;
                $bAliased = $b instanceof AliasPackage;
                if ($aAliased && !$bAliased) {
                    return -1; // use a
                }
                if (!$aAliased && $bAliased) {
                    return 1; // use b
                }
            }

            if (!$ignoreReplace) {
                // return original, not replaced
                if ($this->replaces($a, $b)) {
                    return 1; // use b
                }
                if ($this->replaces($b, $a)) {
                    return -1; // use a
                }
            }

            // priority equal, sort by package id to make reproducible
            if ($a->getId() === $b->getId()) {
                return 0;
            }

            return ($a->getId() < $b->getId()) ? -1 : 1;
        }

        if (isset($installedMap[$a->getId()])) {
            return -1;
        }

        if (isset($installedMap[$b->getId()])) {
            return 1;
        }

        return ($this->getPriority($pool, $a) > $this->getPriority($pool, $b)) ? -1 : 1;
    }

    /**
     * Checks if source replaces a package with the same name as target.
     *
     * Replace constraints are ignored. This method should only be used for
     * prioritisation, not for actual constraint verification.
     *
     * @param PackageInterface $source
     * @param PackageInterface $target
     * @return bool
     */
    protected function replaces(PackageInterface $source, PackageInterface $target)
    {
        foreach ($source->getReplaces() as $link) {
            if ($link->getTarget() === $target->getName()
//                && (null === $link->getConstraint() ||
//                $link->getConstraint()->matches(new VersionConstraint('==', $target->getVersion())))) {
                ) {
                return true;
            }
        }

        return false;
    }

    protected function pruneToBestVersion(Pool $pool, $literals)
    {
        $bestLiterals = array($literals[0]);
        $bestPackage = $pool->literalToPackage($literals[0]);
        foreach ($literals as $i => $literal) {
            if (0 === $i) {
                continue;
            }

            $package = $pool->literalToPackage($literal);

            if ($this->versionCompare($package, $bestPackage, '>')) {
                $bestPackage = $package;
                $bestLiterals = array($literal);
            } elseif ($this->versionCompare($package, $bestPackage, '==')) {
                $bestLiterals[] = $literal;
            }
        }

        return $bestLiterals;
    }

    protected function selectNewestPackages(array $installedMap, array $literals)
    {
        $maxLiterals = array($literals[0]);
        $maxPackage = $literals[0]->getPackage();
        foreach ($literals as $i => $literal) {
            if (0 === $i) {
                continue;
            }

            if ($this->versionCompare($literal->getPackage(), $maxPackage, '>')) {
                $maxPackage = $literal->getPackage();
                $maxLiterals = array($literal);
            } elseif ($this->versionCompare($literal->getPackage(), $maxPackage, '==')) {
                $maxLiterals[] = $literal;
            }
        }

        return $maxLiterals;
    }

    /**
     * Assumes that installed packages come first and then all highest priority packages
     */
    protected function pruneToHighestPriorityOrInstalled(Pool $pool, array $installedMap, array $literals)
    {
        $selected = array();

        $priority = null;

        foreach ($literals as $literal) {
            $package = $pool->literalToPackage($literal);

            if (isset($installedMap[$package->getId()])) {
                $selected[] = $literal;
                continue;
            }

            if (null === $priority) {
                $priority = $this->getPriority($pool, $package);
            }

            if ($this->getPriority($pool, $package) != $priority) {
                break;
            }

            $selected[] = $literal;
        }

        return $selected;
    }

    /**
     * Assumes that locally aliased (in root package requires) packages take priority over branch-alias ones
     *
     * If no package is a local alias, nothing happens
     */
    protected function pruneRemoteAliases(Pool $pool, array $literals)
    {
        $hasLocalAlias = false;

        foreach ($literals as $literal) {
            $package = $pool->literalToPackage($literal);

            if ($package instanceof AliasPackage && $package->isRootPackageAlias()) {
                $hasLocalAlias = true;
                break;
            }
        }

        if (!$hasLocalAlias) {
            return $literals;
        }

        $selected = array();
        foreach ($literals as $literal) {
            $package = $pool->literalToPackage($literal);

            if ($package instanceof AliasPackage && $package->isRootPackageAlias()) {
                $selected[] = $literal;
            }
        }

        return $selected;
    }
}
