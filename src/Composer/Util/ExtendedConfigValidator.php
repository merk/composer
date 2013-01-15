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

namespace Composer\Util;

use Composer\Composer;
use Composer\DependencyResolver\Pool;
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Package\LinkConstraint\MultiConstraint;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\PackageInterface;

/**
 * Provides extended composer configuration validation.
 *
 * @author Tim Nagel <tim@nagel.com.au>
 */
class ExtendedConfigValidator extends ConfigValidator
{
    protected $composer;
    protected $pool;

    public function __construct(IOInterface $io, Composer $composer)
    {
        parent::__construct($io);

        $this->composer = $composer;
    }

    protected function doValidate(array $manifest, array $errors, array $publishErrors)
    {
        list ($errors, $publishErrors, $warnings) = parent::doValidate($manifest, $errors, $publishErrors);

        if (!isset($manifest['extra']['branch-alias'])) {
            $warnings[] = 'Provide a branch alias to make it easier for developers to reference development versions of this package';
        }

        if (!empty($manifest['minimum-stability']) && BasePackage::STABILITY_STABLE !== $manifest['minimum-stability']) {
            $warnings[] = 'For production applications, minimum stability should be set to stable with individual dependencies flagged as unstable as required.';
        }

        $rootPackage = $this->composer->getPackage();
        $required = array_merge(
            $rootPackage->getRequires(),
            $rootPackage->getDevRequires()
        );

        foreach ($required as $link) {
            $this->processLinkConstraints($link, $warnings);
        }

        return array($errors, $publishErrors, $warnings);
    }

    protected function processLinkConstraints(Link $link, array &$warnings)
    {
        if ($link->getConstraint() instanceof MultiConstraint) {
            $constraints = $link->getConstraint()->getConstraints();
        } else {
            $constraints = array($link->getConstraint());
        }

        $hasLower = $hasUpper = $hasStar = $hasMaster = false;
        $pool   = $this->getPool();
        $target = $link->getTarget();

        foreach ($constraints as $constraint) {
            /** @var $constraint \Composer\Package\LinkConstraint\LinkConstraintInterface */

            if (false !== stripos($constraint->getPrettyString(), '*')) {
                $hasStar = true;
            } else if (false !== stripos($constraint->getPrettyString(), 'dev-master')) {
                $hasMaster = true;
            }

            if ($constraint instanceof VersionConstraint) {
                if (in_array($constraint->getOperator(), array('>', '>='))) {
                    $hasLower = true;
                }

                if (in_array($constraint->getOperator(), array('<', '<='))) {
                    $hasUpper = true;
                }
            }
        }

        if ($hasLower && !$hasUpper) {
            $warnings[] = sprintf('%s: Missing an upper bound to the constraint; See \'The Next Significant Release\' - http://getcomposer.org/doc/01-basic-usage.md#package-versions', $target);
        }

        if ($hasStar) {
            $warnings[] = sprintf('%s: The use of * is discouraged and may lead to inconsistent or unexpected results. Use a more specific version constraint.', $target);
        }

        if ($hasMaster) {
            $warnings[] = sprintf('%s: The use of dev-master is discouraged as it may not mean the latest development copy. Use a more specific version constraint.', $target);
        }
    }

    protected function getPool()
    {
        if (null === $this->pool) {
            $repositories = $this->composer->getRepositoryManager()->getRepositories();
            $this->pool = new Pool;
            foreach ($repositories as $repository) {
                $this->pool->addRepository($repository);
            }
        }

        return $this->pool;
    }

    protected function getLatestPackage(array $packages)
    {
        $that = $this;
        uasort($packages, function (PackageInterface $a, PackageInterface $b) use ($that) {
            return $that->versionCompare($a->getVersion(), $b->getVersion(), null);
        });

        return reset($packages);
    }

    /**
     * Temporary - comes from VersionConstraint, should be moved to a Util class
     */
    public function versionCompare($a, $b, $operator)
    {
        if ('dev-' === substr($a, 0, 4) && 'dev-' === substr($b, 0, 4)) {
            return $operator == '==' && $a === $b;
        }

        return version_compare($a, $b, $operator);
    }
}